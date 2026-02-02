<?php
session_start();

require_once __DIR__ . "/flash.php";
$flash = get_flash();
$alert_msg = "";
$alert_type = "";
$alert_timeout = 10;

if ($flash) {
    $alert_msg = $flash['message'];
    $alert_type = $flash['type'];
}

// ===========

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Database connection
$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get statistics
$stats = [
    'total_volunteers' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role='vol' AND status='verified'")->fetch_assoc()['count'],
    'total_organizations' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role='org' AND status='verified'")->fetch_assoc()['count'],
    'pending_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status='pending'")->fetch_assoc()['count'],
    'suspended_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status='suspended'")->fetch_assoc()['count'],
    'total_skills' => $conn->query("SELECT COUNT(*) as count FROM skills")->fetch_assoc()['count'],
    'total_interests' => $conn->query("SELECT COUNT(*) as count FROM interests")->fetch_assoc()['count'],
    'total_opportunities' => $conn->query("SELECT COUNT(*) as count FROM opportunities WHERE status != 'deleted'")->fetch_assoc()['count'],
    'open_opportunities' => $conn->query("SELECT COUNT(*) as count FROM opportunities WHERE status = 'open'")->fetch_assoc()['count'],
];

// Fetch PENDING users with their details
$pending_users_query = "
    SELECT 
        u.user_id, u.username, u.email, u.role, u.status, u.created_at,
        v.first_name, v.last_name, v.city, v.state, v.country,
        o.name as org_name, o.city as org_city, o.contact_info, o.document_paths
    FROM users u
    LEFT JOIN volunteers v ON u.user_id = v.vol_id AND u.role = 'vol'
    LEFT JOIN organizations o ON u.user_id = o.org_id AND u.role = 'org'
    WHERE u.status = 'pending' AND u.role IN ('vol', 'org')
    ORDER BY u.created_at DESC
";

$pending_users_result = $conn->query($pending_users_query);
$pending_users = [];
if ($pending_users_result) {
    while ($user = $pending_users_result->fetch_assoc()) {
        $pending_users[] = $user;
    }
}

// Fetch ACTIVE (verified) users with their details
$active_users_query = "
    SELECT 
        u.user_id, u.username, u.email, u.role, u.status, u.created_at,
        v.first_name, v.last_name, v.city, v.state, v.country,
        o.name as org_name, o.city as org_city, o.contact_info
    FROM users u
    LEFT JOIN volunteers v ON u.user_id = v.vol_id AND u.role = 'vol'
    LEFT JOIN organizations o ON u.user_id = o.org_id AND u.role = 'org'
    WHERE u.status = 'verified' AND u.role IN ('vol', 'org')
    ORDER BY u.created_at DESC
";

$active_users_result = $conn->query($active_users_query);
$active_users = [];
if ($active_users_result) {
    while ($user = $active_users_result->fetch_assoc()) {
        $active_users[] = $user;
    }
}

// Fetch SUSPENDED users with their details
$suspended_users_query = "
    SELECT 
        u.user_id, u.username, u.email, u.role, u.status, u.created_at,
        v.first_name, v.last_name, v.city, v.state, v.country,
        o.name as org_name, o.city as org_city, o.contact_info
    FROM users u
    LEFT JOIN volunteers v ON u.user_id = v.vol_id AND u.role = 'vol'
    LEFT JOIN organizations o ON u.user_id = o.org_id AND u.role = 'org'
    WHERE u.status = 'suspended' AND u.role IN ('vol', 'org')
    ORDER BY u.created_at DESC
";

$suspended_users_result = $conn->query($suspended_users_query);
$suspended_users = [];
if ($suspended_users_result) {
    while ($user = $suspended_users_result->fetch_assoc()) {
        $suspended_users[] = $user;
    }
}

// Fetch skills with categories
$skills_query = "
    SELECT s.*, sc.category_name 
    FROM skills s 
    LEFT JOIN skill_categories sc ON s.category_id = sc.category_id 
    ORDER BY s.skill_name ASC
";
$skills = $conn->query($skills_query);

// Fetch interests with categories
$interests_query = "
    SELECT i.*, ic.category_name 
    FROM interests i 
    LEFT JOIN interest_categories ic ON i.category_id = ic.category_id 
    ORDER BY i.interest_name ASC
";
$interests = $conn->query($interests_query);

// Fetch opportunities
$opportunities_query = "
    SELECT o.*, u.username as org_username 
    FROM opportunities o 
    LEFT JOIN users u ON o.org_id = u.user_id 
    WHERE o.status != 'deleted' 
    ORDER BY o.created_at DESC 
    LIMIT 10
";
$opportunities = $conn->query($opportunities_query);

// Fetch skill categories
$skill_categories = $conn->query("SELECT * FROM skill_categories ORDER BY category_name ASC");

// Fetch interest categories
$interest_categories = $conn->query("SELECT * FROM interest_categories ORDER BY category_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Volunteer Connect</title>
    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">
    <link rel="stylesheet" href="assets/css/vc-admin.css">
    <link rel="stylesheet" href="assets/css/alerts.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="vc-admin-wrapper">
        <!-- Sidebar -->
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
                <a href="#" class="vc-nav-item active" data-tab="dashboard">
                    <div class="vc-nav-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="vc-nav-text">Dashboard</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="pending">
                    <div class="vc-nav-icon"><i class="fas fa-clock"></i></div>
                    <div class="vc-nav-text">Pending Approval</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="active">
                    <div class="vc-nav-icon"><i class="fas fa-users"></i></div>
                    <div class="vc-nav-text">Active Users</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="suspended">
                    <div class="vc-nav-icon"><i class="fas fa-ban"></i></div>
                    <div class="vc-nav-text">Suspended Users</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="skills">
                    <div class="vc-nav-icon"><i class="fas fa-tools"></i></div>
                    <div class="vc-nav-text">Skills</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="interests">
                    <div class="vc-nav-icon"><i class="fas fa-heart"></i></div>
                    <div class="vc-nav-text">Interests</div>
                </a>
                <a href="announcement.php" class="vc-nav-item">
                    <div class="vc-nav-icon"><i class="fas fa-bullhorn"></i></div>
                    <div class="vc-nav-text">Announcement</div>
                </a>
                <a href="#" class="vc-nav-item" data-tab="opportunities">
                    <div class="vc-nav-icon"><i class="fas fa-tasks"></i></div>
                    <div class="vc-nav-text">Opportunities</div>
                </a>
                <a href="reports.php" class="vc-nav-item">
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
                <h1 class="vc-header-title" id="vcPageTitle">Admin Dashboard</h1>
                <p class="vc-header-subtitle" id="vcPageSubtitle">Manage volunteers, organizations, skills, interests, and opportunities</p>
            </div>
            <a href="logout.php" class="vc-logout-btn" onclick="return confirm('Confirm logout?')">
                <i class="fas fa-sign-out-alt"></i>
                Logout</a>
        </header>

        <?php
        // Display flash messages
        $flash = get_flash();
        if ($flash): ?>
            <div class="vc-flash-message vc-flash-<?php echo $flash['type']; ?>">
                <div class="vc-flash-icon">
                    <?php 
                    if ($flash['type'] === 'success') echo '✓';
                    elseif ($flash['type'] === 'error') echo '✖';
                    else echo 'ℹ';
                    ?>
                </div>
                <div class="vc-flash-text"><?php echo htmlspecialchars($flash['message']); ?></div>
                <button class="vc-flash-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            
            <script>
            // Auto-remove flash message after 5 seconds
            setTimeout(() => {
                const flashMsg = document.querySelector('.vc-flash-message');
                if (flashMsg) {
                    flashMsg.style.opacity = '0';
                    setTimeout(() => flashMsg.remove(), 300);
                }
            }, 5000);
            </script>
        <?php endif; ?>

            <!-- Stats Cards -->
            <div class="vc-stats-grid">
                <div class="vc-stat-card vc-stat-volunteers">
                    <div class="vc-stat-icon"><i class="fas fa-hands-helping"></i></div>
                    <div class="vc-stat-value"><?php echo $stats['total_volunteers']; ?></div>
                    <div class="vc-stat-label">Volunteers</div>
                </div>
                
                <div class="vc-stat-card vc-stat-organizations">
                    <div class="vc-stat-icon"><i class="fas fa-building"></i></div>
                    <div class="vc-stat-value"><?php echo $stats['total_organizations']; ?></div>
                    <div class="vc-stat-label">Organizations</div>
                </div>
                
                <div class="vc-stat-card vc-stat-pending">
                    <div class="vc-stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="vc-stat-value"><?php echo $stats['pending_users']; ?></div>
                    <div class="vc-stat-label">Pending</div>
                </div>
                
                <div class="vc-stat-card vc-stat-suspended">
                    <div class="vc-stat-icon"><i class="fas fa-ban"></i></div>
                    <div class="vc-stat-value"><?php echo $stats['suspended_users']; ?></div>
                    <div class="vc-stat-label">Suspended</div>
                </div>
            </div>

            <!-- Additional Quick Stats -->
            <div class="vc-quick-stats">
                <div class="vc-quick-stat">
                    <div class="vc-quick-stat-value"><?php echo $stats['total_skills']; ?></div>
                    <div class="vc-quick-stat-label">Skills</div>
                </div>
                <div class="vc-quick-stat">
                    <div class="vc-quick-stat-value"><?php echo $stats['total_interests']; ?></div>
                    <div class="vc-quick-stat-label">Interests</div>
                </div>
                <div class="vc-quick-stat">
                    <div class="vc-quick-stat-value"><?php echo $stats['total_opportunities']; ?></div>
                    <div class="vc-quick-stat-label">Opportunities</div>
                </div>
                <div class="vc-quick-stat">
                    <div class="vc-quick-stat-value"><?php echo $stats['open_opportunities']; ?></div>
                    <div class="vc-quick-stat-label">Open</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="vc-tabs">
                <div class="vc-tab-headers">
                    <button class="vc-tab-btn active" data-tab="dashboard">
                        <i class="fas fa-tachometer-alt"></i> Overview
                    </button>
                    <button class="vc-tab-btn" data-tab="pending">
                        <i class="fas fa-clock"></i> Pending
                    </button>
                    <button class="vc-tab-btn" data-tab="active">
                        <i class="fas fa-check-circle"></i> Active
                    </button>
                    <button class="vc-tab-btn" data-tab="suspended">
                        <i class="fas fa-ban"></i> Suspended
                    </button>
                    <button class="vc-tab-btn" data-tab="skills">
                        <i class="fas fa-tools"></i> Skills
                    </button>
                    <button class="vc-tab-btn" data-tab="interests">
                        <i class="fas fa-heart"></i> Interests
                    </button>
                    <button class="vc-tab-btn" data-tab="opportunities">
                        <i class="fas fa-tasks"></i> Opportunities
                    </button>
                </div>

                <!-- Dashboard Tab -->
                <div id="dashboard" class="vc-tab-content active">
                    <div class="vc-table-container">
                        <h3 class="vc-table-title">
                            <i class="fas fa-chart-line"></i>
                            System Overview
                        </h3>
                        <div class="vc-skills-interests">
                            <div class="vc-skills-card">
                                <h4>Recent Users</h4>
                                <?php 
                                $recent_users = $conn->query("
                                    SELECT u.username, u.role, u.created_at 
                                    FROM users u 
                                    WHERE u.role IN ('vol', 'org') 
                                    ORDER BY u.created_at DESC 
                                    LIMIT 5
                                ");
                                if ($recent_users && $recent_users->num_rows > 0): ?>
                                    <?php while($user = $recent_users->fetch_assoc()): ?>
                                        <div class="vc-skill-item">
                                            <div>
                                                <span class="vc-skill-name"><?php echo htmlspecialchars($user['username']); ?></span>
                                                <span class="vc-skill-category"><?php echo $user['role'] === 'vol' ? 'Volunteer' : 'Organization'; ?></span>
                                            </div>
                                            <div class="vc-skill-category">
                                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <p>No users found</p>
                                <?php endif; ?>
                            </div>
                            <div class="vc-interests-card">
                                <h4>Recent Opportunities</h4>
                                <?php 
                                $recent_opps = $conn->query("
                                    SELECT o.title, o.status, o.created_at 
                                    FROM opportunities o 
                                    ORDER BY o.created_at DESC 
                                    LIMIT 5
                                ");
                                if ($recent_opps && $recent_opps->num_rows > 0): ?>
                                    <?php while($opp = $recent_opps->fetch_assoc()): ?>
                                        <div class="vc-interest-item">
                                            <div>
                                                <span class="vc-interest-name"><?php echo htmlspecialchars($opp['title']); ?></span>
                                                <span class="vc-interest-category"><?php echo ucfirst($opp['status']); ?></span>
                                            </div>
                                            <div class="vc-interest-category">
                                                <?php echo date('M d', strtotime($opp['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <p>No opportunities found</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Users Tab -->
                <div id="pending" class="vc-tab-content">
                    <?php if (!empty($pending_users)): ?>
                        <div class="vc-table-container">
                            <h3 class="vc-table-title">
                                <i class="fas fa-clock"></i>
                                Users Pending Approval (<?php echo count($pending_users); ?>)
                            </h3>
                            
                            <!-- Filter Controls -->
                            <div class="vc-filter-controls">
                                <span class="vc-filter-label">Filter by:</span>
                                <select class="vc-filter-select vc-user-type-filter">
                                    <option value="">All User Types</option>
                                    <option value="vol">Volunteers Only</option>
                                    <option value="org">Organizations Only</option>
                                </select>
                                <button class="vc-filter-btn vc-apply-filter" data-tab="pending">
                                    <i class="fas fa-filter"></i> Apply Filter
                                </button>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="pending">
                                    <i class="fas fa-times"></i> Clear All
                                </button>
                                <span class="vc-filter-results">Showing <?php echo count($pending_users); ?> of <?php echo count($pending_users); ?> users</span>
                            </div>
                            
                            <!-- Active Filter Badges -->
                            <div class="vc-filter-badges" id="pending-filter-badges">
                                <!-- Filter badges will be dynamically added here -->
                            </div>
                            
                            <table class="vc-table" id="pending-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Registered</th>
                                        <th>Location</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_users as $user): ?>
                                        <tr data-user-type="<?php echo $user['role']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php 
                                                if ($user['role'] === 'vol') {
                                                    echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
                                                } else {
                                                    echo htmlspecialchars($user['org_name'] ?? 'Organization');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="vc-status-badge vc-status-pending">
                                                    <?php echo $user['role'] === 'vol' ? 'Volunteer' : 'Organization'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <?php 
                                                if ($user['role'] === 'vol') {
                                                    $location = [];
                                                    if (!empty($user['city'])) $location[] = $user['city'];
                                                    if (!empty($user['state'])) $location[] = $user['state'];
                                                    echo htmlspecialchars(implode(', ', $location));
                                                } else {
                                                    echo htmlspecialchars($user['org_city'] ?? 'N/A');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="vc-action-buttons">
                                                    <?php if ($user['role'] === 'org' && !empty($user['document_paths'])): ?>
                                                        <a href="#"
                                                        onclick='openDocsModal(<?= json_encode($user["document_paths"]) ?>)'
                                                        class="vc-btn vc-btn-sm vc-btn-edit">
                                                            <i class="fas fa-file-alt"></i> Review Docs
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="#" 
                                                    onclick="approveUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')"
                                                    class="vc-btn vc-btn-sm vc-btn-approve">
                                                        <i class="fas fa-check"></i> Approve
                                                    </a>
                                                    <a href="#" 
                                                    onclick="rejectUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')"
                                                    class="vc-btn vc-btn-sm vc-btn-reject">
                                                        <i class="fas fa-times"></i> Reject
                                                    </a>
                                                    <a href="#" 
                                                    onclick="suspendUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>', 'pending')"
                                                    class="vc-btn vc-btn-sm vc-btn-suspend">
                                                        <i class="fas fa-ban"></i> Suspend
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Empty Filter State (hidden by default) -->
                            <div class="vc-empty-filter" id="pending-empty-filter" style="display: none;">
                                <div class="vc-empty-filter-icon"><i class="fas fa-search"></i></div>
                                <h3>No Users Match Your Filters</h3>
                                <p>Try adjusting your filter criteria</p>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="pending" style="margin-top: 15px;">
                                    <i class="fas fa-times"></i> Clear All Filters
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="vc-empty-state">
                            <div class="vc-empty-icon"><i class="fas fa-check-circle"></i></div>
                            <h3>No Pending Approvals</h3>
                            <p>All users have been processed</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Active Users Tab -->
                <div id="active" class="vc-tab-content">
                    <?php if (!empty($active_users)): ?>
                        <div class="vc-table-container">
                            <h3 class="vc-table-title">
                                <i class="fas fa-users"></i>
                                Active Users (<?php echo count($active_users); ?>)
                            </h3>
                            
                            <!-- Filter Controls -->
                            <div class="vc-filter-controls">
                                <span class="vc-filter-label">Filter by:</span>
                                <select class="vc-filter-select vc-user-type-filter">
                                    <option value="">All User Types</option>
                                    <option value="vol">Volunteers Only</option>
                                    <option value="org">Organizations Only</option>
                                </select>
                                <button class="vc-filter-btn vc-apply-filter" data-tab="active">
                                    <i class="fas fa-filter"></i> Apply Filter
                                </button>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="active">
                                    <i class="fas fa-times"></i> Clear All
                                </button>
                                <span class="vc-filter-results">Showing <?php echo count($active_users); ?> of <?php echo count($active_users); ?> users</span>
                            </div>
                            
                            <!-- Active Filter Badges -->
                            <div class="vc-filter-badges" id="active-filter-badges">
                                <!-- Filter badges will be dynamically added here -->
                            </div>
                            
                            <table class="vc-table" id="active-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_users as $user): ?>
                                        <tr data-user-type="<?php echo $user['role']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php 
                                                if ($user['role'] === 'vol') {
                                                    echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
                                                } else {
                                                    echo htmlspecialchars($user['org_name'] ?? 'Organization');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="vc-status-badge vc-status-approved">
                                                    <?php echo $user['role'] === 'vol' ? 'Volunteer' : 'Organization'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($user['role'] === 'vol') {
                                                    $location = [];
                                                    if (!empty($user['city'])) $location[] = $user['city'];
                                                    if (!empty($user['state'])) $location[] = $user['state'];
                                                    echo htmlspecialchars(implode(', ', $location));
                                                } else {
                                                    echo htmlspecialchars($user['org_city'] ?? 'N/A');
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="vc-action-buttons">
                                                    <button onclick="openEditModal(
                                                        '<?php echo $user['role']; ?>',
                                                        <?php echo $user['user_id']; ?>,
                                                        '<?php echo addslashes(
                                                            $user['role'] === 'vol' 
                                                            ? $user['first_name'] . ' ' . $user['last_name']
                                                            : ($user['org_name'] ?? 'Organization')
                                                        ); ?>',
                                                        '<?php echo addslashes($user['email']); ?>',
                                                        '<?php echo $user['role'] === 'vol' ? 'Location' : 'Contact Info'; ?>',
                                                        '<?php echo addslashes(
                                                            $user['role'] === 'vol' 
                                                            ? ($user['city'] ?? '') . ($user['state'] ? ', ' . $user['state'] : '')
                                                            : ($user['contact_info'] ?? '')
                                                        ); ?>'
                                                    )" class="vc-btn vc-btn-sm vc-btn-edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <a href="#" 
                                                    onclick="suspendUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>', 'active')"
                                                    class="vc-btn vc-btn-sm vc-btn-suspend">
                                                        <i class="fas fa-ban"></i> Suspend
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Empty Filter State (hidden by default) -->
                            <div class="vc-empty-filter" id="active-empty-filter" style="display: none;">
                                <div class="vc-empty-filter-icon"><i class="fas fa-search"></i></div>
                                <h3>No Users Match Your Filters</h3>
                                <p>Try adjusting your filter criteria</p>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="active" style="margin-top: 15px;">
                                    <i class="fas fa-times"></i> Clear All Filters
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="vc-empty-state">
                            <div class="vc-empty-icon"><i class="fas fa-users"></i></div>
                            <h3>No Active Users</h3>
                            <p>No users are currently active</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Suspended Users Tab -->
                <div id="suspended" class="vc-tab-content">
                    <?php if (!empty($suspended_users)): ?>
                        <div class="vc-table-container">
                            <h3 class="vc-table-title">
                                <i class="fas fa-ban"></i>
                                Suspended Users (<?php echo count($suspended_users); ?>)
                            </h3>
                            
                            <!-- Filter Controls -->
                            <div class="vc-filter-controls">
                                <span class="vc-filter-label">Filter by:</span>
                                <select class="vc-filter-select vc-user-type-filter">
                                    <option value="">All User Types</option>
                                    <option value="vol">Volunteers Only</option>
                                    <option value="org">Organizations Only</option>
                                </select>
                                <button class="vc-filter-btn vc-apply-filter" data-tab="suspended">
                                    <i class="fas fa-filter"></i> Apply Filter
                                </button>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="suspended">
                                    <i class="fas fa-times"></i> Clear All
                                </button>
                                <span class="vc-filter-results">Showing <?php echo count($suspended_users); ?> of <?php echo count($suspended_users); ?> users</span>
                            </div>
                            
                            <!-- Active Filter Badges -->
                            <div class="vc-filter-badges" id="suspended-filter-badges">
                                <!-- Filter badges will be dynamically added here -->
                            </div>
                            
                            <table class="vc-table" id="suspended-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Suspended Since</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suspended_users as $user): ?>
                                        <tr data-user-type="<?php echo $user['role']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php 
                                                if ($user['role'] === 'vol') {
                                                    echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
                                                } else {
                                                    echo htmlspecialchars($user['org_name'] ?? 'Organization');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="vc-status-badge vc-status-suspended">
                                                    <?php echo $user['role'] === 'vol' ? 'Volunteer' : 'Organization'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="vc-action-buttons">
                                                    <a href="#" 
                                                    onclick="restoreUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')"
                                                    class="vc-btn vc-btn-sm vc-btn-restore">
                                                        <i class="fas fa-redo"></i> Restore
                                                    </a>
                                                    <a href="#" 
                                                    onclick="deleteUser('<?php echo $user['user_id']; ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')"
                                                    class="vc-btn vc-btn-sm vc-btn-delete">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Empty Filter State (hidden by default) -->
                            <div class="vc-empty-filter" id="suspended-empty-filter" style="display: none;">
                                <div class="vc-empty-filter-icon"><i class="fas fa-search"></i></div>
                                <h3>No Users Match Your Filters</h3>
                                <p>Try adjusting your filter criteria</p>
                                <button class="vc-filter-btn vc-filter-btn-reset vc-clear-filter" data-tab="suspended" style="margin-top: 15px;">
                                    <i class="fas fa-times"></i> Clear All Filters
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="vc-empty-state">
                            <div class="vc-empty-icon"><i class="fas fa-ban"></i></div>
                            <h3>No Suspended Users</h3>
                            <p>All users are currently active</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Skills Management Tab -->
                <div id="skills" class="vc-tab-content">
                    <div class="vc-table-container">
                        <h3 class="vc-table-title">
                            <i class="fas fa-tools"></i>
                            Skills Management (<?php echo $stats['total_skills']; ?>)
                        </h3>
                        
                        <!-- Add New Skill Form -->
                        <div class="vc-add-form">
                            <h4>Add New Skill</h4>
                            <form id="vcAddSkillForm" method="POST" action="admin_skills_action.php">
                                <input type="hidden" name="action" value="add">
                                <div class="vc-form-row">
                                    <input type="text" class="vc-form-control" name="skill_name" placeholder="Skill Name" required>
                                    <select class="vc-form-select" name="category_id" id="vcSkillCategory">
                                        <option value="">Select Category</option>
                                        <?php 
                                        $skill_categories->data_seek(0);
                                        while($category = $skill_categories->fetch_assoc()): ?>
                                            <option value="<?php echo $category['category_id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <button type="submit" class="vc-btn vc-btn-approve">
                                        <i class="fas fa-plus"></i> Add Skill
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Skills List -->
                        <?php if ($skills && $skills->num_rows > 0): ?>
                            <div style="margin-top: 20px;">
                                <h4>Existing Skills</h4>
                                <?php while($skill = $skills->fetch_assoc()): ?>
                                    <div class="vc-skill-item">
                                        <div>
                                            <span class="vc-skill-name"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                                            <?php if ($skill['category_name']): ?>
                                                <span class="vc-skill-category"><?php echo htmlspecialchars($skill['category_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="vc-skill-actions">
                                            <button onclick="editSkill(<?php echo $skill['skill_id']; ?>, '<?php echo addslashes($skill['skill_name']); ?>', <?php echo $skill['category_id'] ?? 'null'; ?>)" 
                                                    class="vc-btn vc-btn-sm vc-btn-edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="admin_skills_action.php?action=delete&id=<?php echo $skill['skill_id']; ?>" 
                                               class="vc-btn vc-btn-sm vc-btn-delete vc-delete-skill" 
                                               data-skill-id="<?php echo $skill['skill_id']; ?>"
                                               data-confirm="true" 
                                               data-confirm-message="Delete this skill?">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="vc-empty-state">
                                <div class="vc-empty-icon"><i class="fas fa-tools"></i></div>
                                <h3>No Skills Found</h3>
                                <p>Add your first skill using the form above</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Interests Management Tab -->
                <div id="interests" class="vc-tab-content">
                    <div class="vc-table-container">
                        <h3 class="vc-table-title">
                            <i class="fas fa-heart"></i>
                            Interests Management (<?php echo $stats['total_interests']; ?>)
                        </h3>
                        
                        <!-- Add New Interest Form -->
                        <div class="vc-add-form">
                            <h4>Add New Interest</h4>
                            <form id="vcAddInterestForm" method="POST" action="admin_interests_action.php">
                                <input type="hidden" name="action" value="add">
                                <div class="vc-form-row">
                                    <input type="text" class="vc-form-control" name="interest_name" placeholder="Interest Name" required>
                                    <select class="vc-form-select" name="category_id" id="vcInterestCategory">
                                        <option value="">Select Category</option>
                                        <?php 
                                        $interest_categories->data_seek(0);
                                        while($category = $interest_categories->fetch_assoc()): ?>
                                            <option value="<?php echo $category['category_id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <button type="submit" class="vc-btn vc-btn-approve">
                                        <i class="fas fa-plus"></i> Add Interest
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Interests List -->
                        <?php if ($interests && $interests->num_rows > 0): ?>
                            <div style="margin-top: 20px;">
                                <h4>Existing Interests</h4>
                                <?php while($interest = $interests->fetch_assoc()): ?>
                                    <div class="vc-interest-item">
                                        <div>
                                            <span class="vc-interest-name"><?php echo htmlspecialchars($interest['interest_name']); ?></span>
                                            <?php if ($interest['category_name']): ?>
                                                <span class="vc-interest-category"><?php echo htmlspecialchars($interest['category_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="vc-interest-actions">
                                            <button onclick="editInterest(<?php echo $interest['interest_id']; ?>, '<?php echo addslashes($interest['interest_name']); ?>', <?php echo $interest['category_id'] ?? 'null'; ?>)" 
                                                    class="vc-btn vc-btn-sm vc-btn-edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="admin_interests_action.php?action=delete&id=<?php echo $interest['interest_id']; ?>" 
                                               class="vc-btn vc-btn-sm vc-btn-delete vc-delete-interest" 
                                               data-interest-id="<?php echo $interest['interest_id']; ?>"
                                               data-confirm="true" 
                                               data-confirm-message="Delete this interest?">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="vc-empty-state">
                                <div class="vc-empty-icon"><i class="fas fa-heart"></i></div>
                                <h3>No Interests Found</h3>
                                <p>Add your first interest using the form above</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Opportunities Tab -->
                <div id="opportunities" class="vc-tab-content">
                    <div class="vc-table-container">
                        <h3 class="vc-table-title">
                            <i class="fas fa-tasks"></i>
                            Recent Opportunities (<?php echo $stats['total_opportunities']; ?> total)
                        </h3>
                        
                        <?php if ($opportunities && $opportunities->num_rows > 0): ?>
                            <table class="vc-table" id="opportunities-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Organization</th>
                                        <th>Location</th>
                                        <th>Date</th>
                                        <th>Volunteers</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($opp = $opportunities->fetch_assoc()): ?>
                                        <tr data-opportunity-id="<?php echo $opp['opportunity_id']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($opp['title']); ?></strong><br>
                                                <small class="vc-opportunity-date"><?php echo date('M d, Y', strtotime($opp['created_at'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($opp['org_username'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                $location = [];
                                                if (!empty($opp['city'])) $location[] = $opp['city'];
                                                if (!empty($opp['state'])) $location[] = $opp['state'];
                                                echo htmlspecialchars(implode(', ', $location));
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($opp['start_date']): ?>
                                                    <?php echo date('M d', strtotime($opp['start_date'])); ?>
                                                    <?php if ($opp['end_date'] && $opp['end_date'] != $opp['start_date']): ?>
                                                        - <?php echo date('d', strtotime($opp['end_date'])); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    TBD
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $opp['number_of_volunteers'] ?? 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                $status_class = 'vc-status-' . $opp['status'];
                                                $status_text = ucfirst($opp['status']);
                                                ?>
                                                <span class="vc-opportunity-status <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="vc-action-buttons">
                                                    <button onclick="viewOpportunity(<?php echo $opp['opportunity_id']; ?>)" 
                                                            class="vc-btn vc-btn-sm vc-btn-edit">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <?php if ($opp['status'] == 'open'): ?>
                                                        <button onclick="suspendOpportunity(<?php echo $opp['opportunity_id']; ?>)" 
                                                                class="vc-btn vc-btn-sm vc-btn-suspend"
                                                                data-confirm="true" 
                                                                data-confirm-message="Suspend this opportunity?">
                                                            <i class="fas fa-pause-circle"></i> Suspend
                                                        </button>
                                                    <?php elseif ($opp['status'] == 'suspended'): ?>
                                                        <button onclick="reactivateOpportunity(<?php echo $opp['opportunity_id']; ?>)" 
                                                                class="vc-btn vc-btn-sm vc-btn-approve"
                                                                data-confirm="true" 
                                                                data-confirm-message="Reactivate this opportunity?">
                                                            <i class="fas fa-play-circle"></i> Reactivate
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="deleteOpportunity(<?php echo $opp['opportunity_id']; ?>)" 
                                                            class="vc-btn vc-btn-sm vc-btn-delete"
                                                            data-confirm="true" 
                                                            data-confirm-message="Delete this opportunity permanently?">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="/volcon/app/browse_opportunities.php" class="vc-btn vc-btn-approve">
                                    <i class="fas fa-list"></i> View All Opportunities
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="vc-empty-state">
                                <div class="vc-empty-icon"><i class="fas fa-tasks"></i></div>
                                <h3>No Opportunities Found</h3>
                                <p>No opportunities have been created yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Opportunity View Modal -->
    <div id="vcOpportunityModal" class="vc-modal vc-opportunity-view-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3 class="vc-modal-title">Opportunity Details</h3>
                <button class="vc-modal-close" onclick="closeOpportunityModal()">&times;</button>
            </div>
            
            <div class="vc-opportunity-loading" id="opportunityLoading">
                <div class="vc-opportunity-loading-icon"><i class="fas fa-spinner"></i></div>
                <p>Loading opportunity details...</p>
            </div>
            
            <div class="vc-opportunity-content" id="opportunityContent" style="display: none;">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="vcEditModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3 class="vc-modal-title">Edit User</h3>
                <button class="vc-modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="vcEditForm" method="POST" action="admin_user_action.php" class="vc-modal-body">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="type" id="vcEditType">
                <input type="hidden" name="id" id="vcEditId">
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Name:</label>
                    <input type="text" class="vc-form-control" name="name" id="vcEditName" required>
                </div>
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Email:</label>
                    <input type="email" class="vc-form-control" name="email" id="vcEditEmail" required>
                </div>
                
                <div id="vcExtraField"></div>
            </form>
            <div class="vc-modal-footer">
                <button type="button" class="vc-btn" onclick="closeEditModal()">Cancel</button>
                <button type="submit" form="vcEditForm" class="vc-btn vc-btn-approve">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Edit Skill Modal -->
    <div id="vcEditSkillModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3 class="vc-modal-title">Edit Skill</h3>
                <button type="button" class="vc-modal-close" onclick="closeEditSkillModal()">&times;</button>
            </div>
            <form id="vcEditSkillForm" class="vc-modal-body" onsubmit="submitEditSkillForm(event)">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="skill_id" id="vcEditSkillId">
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Skill Name:</label>
                    <input type="text" class="vc-form-control" name="skill_name" id="vcEditSkillName" required>
                </div>
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Category:</label>
                    <select class="vc-form-control" name="category_id" id="vcEditSkillCategory">
                        <option value="">No Category</option>
                        <?php 
                        $skill_categories->data_seek(0);
                        while($category = $skill_categories->fetch_assoc()): ?>
                            <option value="<?php echo $category['category_id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="vc-modal-footer">
                    <button type="button" class="vc-btn" onclick="closeEditSkillModal()">Cancel</button>
                    <button type="submit" class="vc-btn vc-btn-approve">
                        <span id="editSkillSubmitText">Save Changes</span>
                        <span id="editSkillLoading" style="display: none;">
                            <i class="fas fa-spinner fa-spin"></i> Saving...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Interest Modal -->
    <div id="vcEditInterestModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3 class="vc-modal-title">Edit Interest</h3>
                <button type="button" class="vc-modal-close" onclick="closeEditInterestModal()">&times;</button>
            </div>
            <form id="vcEditInterestForm" class="vc-modal-body" onsubmit="submitEditInterestForm(event)">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="interest_id" id="vcEditInterestId">
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Interest Name:</label>
                    <input type="text" class="vc-form-control" name="interest_name" id="vcEditInterestName" required>
                </div>
                
                <div class="vc-form-group">
                    <label class="vc-form-label">Category:</label>
                    <select class="vc-form-control" name="category_id" id="vcEditInterestCategory">
                        <option value="">No Category</option>
                        <?php 
                        $interest_categories->data_seek(0);
                        while($category = $interest_categories->fetch_assoc()): ?>
                            <option value="<?php echo $category['category_id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="vc-modal-footer">
                    <button type="button" class="vc-btn" onclick="closeEditInterestModal()">Cancel</button>
                    <button type="submit" class="vc-btn vc-btn-approve">
                        <span id="editInterestSubmitText">Save Changes</span>
                        <span id="editInterestLoading" style="display: none;">
                            <i class="fas fa-spinner fa-spin"></i> Saving...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Review Documents Modal -->
    <div id="vcDocsModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3 class="vc-modal-title">Submitted Verification Documents</h3>
                <button class="vc-modal-close" onclick="closeDocsModal()">&times;</button>
            </div>

            <div class="vc-modal-body" id="vcDocsContent">
                <!-- Loaded dynamically -->
            </div>

            <div class="vc-modal-footer">
                <button class="vc-btn" onclick="closeDocsModal()">Close</button>
            </div>
        </div>
    </div>


    <!-- Scripts -->
    <script src="assets/js/vc-admin.js"></script>

    <script>
        function openDocsModal(documentPathsJson) {
            let docs = [];

            try {
                docs = JSON.parse(documentPathsJson);
            } catch (e) {
                docs = [];
            }

            const container = document.getElementById('vcDocsContent');
            container.innerHTML = '';

            if (!docs.length) {
                container.innerHTML = '<p>No documents submitted.</p>';
            } else {
                const list = document.createElement('ul');
                list.style.listStyle = 'none';
                list.style.padding = '0';

                docs.forEach(doc => {
                    const li = document.createElement('li');
                    li.style.marginBottom = '10px';

                    li.innerHTML = `
                        <a href="${doc.file}" target="_blank" class="vc-btn vc-btn-sm vc-btn-edit">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <span style="margin-left: 10px;">
                            ${doc.original_name || 'Document'}
                        </span>
                        <small style="display:block;color:#888;">
                            Uploaded: ${doc.uploaded_at || ''}
                        </small>
                    `;
                    list.appendChild(li);
                });

                container.appendChild(list);
            }

            document.getElementById('vcDocsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeDocsModal() {
            document.getElementById('vcDocsModal').style.display = 'none';
            document.body.style.overflow = '';
        }

    </script>

    <script>
    // Page title updates based on active tab
    const pageTitles = {
        'dashboard': 'Admin Dashboard',
        'pending': 'Pending Approvals',
        'active': 'Active Users',
        'suspended': 'Suspended Users',
        'skills': 'Skills Management',
        'interests': 'Interests Management',
        'opportunities': 'Opportunities Management'
    };

    const pageSubtitles = {
        'dashboard': 'Manage volunteers, organizations, skills, interests, and opportunities',
        'pending': 'Review and approve pending user registrations',
        'active': 'View and manage all active users in the system',
        'suspended': 'Manage suspended user accounts',
        'skills': 'Add, edit, and manage volunteer skills',
        'interests': 'Add, edit, and manage volunteer interests',
        'opportunities': 'View and manage volunteer opportunities'
    };

    // Update page title when tab changes
    function updatePageTitle(tabId) {
        const titleElement = document.getElementById('vcPageTitle');
        const subtitleElement = document.getElementById('vcPageSubtitle');
        
        if (titleElement && subtitleElement) {
            titleElement.textContent = pageTitles[tabId] || 'Admin Dashboard';
            subtitleElement.textContent = pageSubtitles[tabId] || 'Manage the volunteer connection system';
            
            // Update browser tab title
            document.title = `${pageTitles[tabId]} - Volunteer Connect Admin`;
        }
    }

    // Modify existing tab click handler
    document.addEventListener('DOMContentLoaded', function() {
        const tabBtns = document.querySelectorAll('.vc-tab-btn');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                updatePageTitle(tabId);
            });
        });
        
        // Initial title setup
        const activeTab = document.querySelector('.vc-tab-btn.active');
        if (activeTab) {
            updatePageTitle(activeTab.getAttribute('data-tab'));
        }
    });

    // Edit User Modal functions
    function openEditModal(type, id, name, email, extraLabel, extraValue) {
        document.getElementById('vcEditModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        document.getElementById('vcEditType').value = type;
        document.getElementById('vcEditId').value = id;
        document.getElementById('vcEditName').value = name;
        document.getElementById('vcEditEmail').value = email;

        const extraFieldDiv = document.getElementById('vcExtraField');
        if (type === 'volunteer' || type === 'vol') {
            extraFieldDiv.innerHTML = `
                <div class="vc-form-group">
                    <label class="vc-form-label">Location:</label>
                    <input type="text" class="vc-form-control" name="location" value="${extraValue || ''}">
                </div>
            `;
        } else if (type === 'organization' || type === 'org') {
            extraFieldDiv.innerHTML = `
                <div class="vc-form-group">
                    <label class="vc-form-label">Contact Info:</label>
                    <input type="text" class="vc-form-control" name="contact_info" value="${extraValue || ''}">
                </div>
            `;
        }
    }
    
    function closeEditModal() {
        document.getElementById('vcEditModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    
    // Skill functions
    function editSkill(skillId, skillName, categoryId) {
        document.getElementById('vcEditSkillId').value = skillId;
        document.getElementById('vcEditSkillName').value = skillName;
        document.getElementById('vcEditSkillCategory').value = categoryId || '';
        document.getElementById('vcEditSkillModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeEditSkillModal() {
        document.getElementById('vcEditSkillModal').style.display = 'none';
        document.body.style.overflow = '';
        // Reset form
        document.getElementById('vcEditSkillForm').reset();
    }

    function submitEditSkillForm(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        const submitBtn = form.querySelector('.vc-btn-approve');
        const submitText = document.getElementById('editSkillSubmitText');
        const loadingText = document.getElementById('editSkillLoading');
        
        // Show loading state
        submitText.style.display = 'none';
        loadingText.style.display = 'inline';
        submitBtn.disabled = true;
        
        // Send AJAX request
        fetch('admin_skills_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showAlert('success', data.message || 'Skill updated successfully');
                
                // Close modal
                closeEditSkillModal();
                
                // Update the skill item in the list
                updateSkillInList(data.skill_id || formData.get('skill_id'), {
                    name: formData.get('skill_name'),
                    category: getCategoryName(formData.get('category_id'))
                });
                
                // Optional: Refresh the page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                // Show error message
                showAlert('error', data.message || 'Failed to update skill');
                
                // Reset button state
                submitText.style.display = 'inline';
                loadingText.style.display = 'none';
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'An error occurred while updating the skill');
            
            // Reset button state
            submitText.style.display = 'inline';
            loadingText.style.display = 'none';
            submitBtn.disabled = false;
        });
    }

    // Interest functions
    function editInterest(interestId, interestName, categoryId) {
        document.getElementById('vcEditInterestId').value = interestId;
        document.getElementById('vcEditInterestName').value = interestName;
        document.getElementById('vcEditInterestCategory').value = categoryId || '';
        document.getElementById('vcEditInterestModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeEditInterestModal() {
        document.getElementById('vcEditInterestModal').style.display = 'none';
        document.body.style.overflow = '';
        // Reset form
        document.getElementById('vcEditInterestForm').reset();
    }

    function submitEditInterestForm(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        const submitBtn = form.querySelector('.vc-btn-approve');
        const submitText = document.getElementById('editInterestSubmitText');
        const loadingText = document.getElementById('editInterestLoading');
        
        // Show loading state
        submitText.style.display = 'none';
        loadingText.style.display = 'inline';
        submitBtn.disabled = true;
        
        // Send AJAX request
        fetch('admin_interests_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showAlert('success', data.message || 'Interest updated successfully');
                
                // Close modal
                closeEditInterestModal();
                
                // Update the interest item in the list
                updateInterestInList(data.interest_id || formData.get('interest_id'), {
                    name: formData.get('interest_name'),
                    category: getCategoryName(formData.get('category_id'))
                });
                
                // Optional: Refresh the page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                // Show error message
                showAlert('error', data.message || 'Failed to update interest');
                
                // Reset button state
                submitText.style.display = 'inline';
                loadingText.style.display = 'none';
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'An error occurred while updating the interest');
            
            // Reset button state
            submitText.style.display = 'inline';
            loadingText.style.display = 'none';
            submitBtn.disabled = false;
        });
    }

    // Helper functions
    function showAlert(type, message) {
        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = `vc-alert vc-alert-${type}`;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease;
        `;
        
        // Add CSS for animation
        if (!document.querySelector('#alert-styles')) {
            const style = document.createElement('style');
            style.id = 'alert-styles';
            style.textContent = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
        
        // Set icon based on type
        let icon = 'ℹ';
        if (type === 'success') icon = '✓';
        else if (type === 'error') icon = '✖';
        
        alertDiv.innerHTML = `
            <div style="display: flex; align-items: flex-start; gap: 10px;">
                <div style="font-size: 18px; flex-shrink: 0;">${icon}</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 5px;">${type === 'success' ? 'Success' : 'Error'}</div>
                    <div style="font-size: 14px;">${message}</div>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" 
                        style="background: none; border: none; font-size: 20px; cursor: pointer; color: inherit; opacity: 0.7; padding: 0;">
                    &times;
                </button>
            </div>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 5000);
    }

    function updateSkillInList(skillId, newData) {
        const skillItem = document.querySelector(`.vc-skill-item [data-skill-id="${skillId}"]`)?.closest('.vc-skill-item');
        if (skillItem) {
            const nameSpan = skillItem.querySelector('.vc-skill-name');
            const categorySpan = skillItem.querySelector('.vc-skill-category');
            
            if (nameSpan) nameSpan.textContent = newData.name;
            if (categorySpan) categorySpan.textContent = newData.category;
            
            // Also update the edit button onclick attribute
            const editBtn = skillItem.querySelector('button[onclick*="editSkill"]');
            if (editBtn) {
                const categoryId = document.getElementById('vcEditSkillCategory').value;
                editBtn.setAttribute('onclick', 
                    `editSkill(${skillId}, '${newData.name.replace(/'/g, "\\'")}', ${categoryId || 'null'})`
                );
            }
        }
    }

    function updateInterestInList(interestId, newData) {
        const interestItem = document.querySelector(`.vc-interest-item [data-interest-id="${interestId}"]`)?.closest('.vc-interest-item');
        if (interestItem) {
            const nameSpan = interestItem.querySelector('.vc-interest-name');
            const categorySpan = interestItem.querySelector('.vc-interest-category');
            
            if (nameSpan) nameSpan.textContent = newData.name;
            if (categorySpan) categorySpan.textContent = newData.category;
            
            // Also update the edit button onclick attribute
            const editBtn = interestItem.querySelector('button[onclick*="editInterest"]');
            if (editBtn) {
                const categoryId = document.getElementById('vcEditInterestCategory').value;
                editBtn.setAttribute('onclick', 
                    `editInterest(${interestId}, '${newData.name.replace(/'/g, "\\'")}', ${categoryId || 'null'})`
                );
            }
        }
    }

    function getCategoryName(categoryId) {
        if (!categoryId) return '';
        const select = document.getElementById('vcEditSkillCategory') || document.getElementById('vcEditInterestCategory');
        const option = select?.querySelector(`option[value="${categoryId}"]`);
        return option ? option.textContent : '';
    }
    
    // Close modals with escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeEditModal();
            closeEditSkillModal();
            closeEditInterestModal();
        }
    });
    
    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
        const editModal = document.getElementById('vcEditModal');
        const skillModal = document.getElementById('vcEditSkillModal');
        const interestModal = document.getElementById('vcEditInterestModal');
        
        if (editModal.style.display === 'flex' && event.target === editModal) {
            closeEditModal();
        }
        if (skillModal.style.display === 'flex' && event.target === skillModal) {
            closeEditSkillModal();
        }
        if (interestModal.style.display === 'flex' && event.target === interestModal) {
            closeEditInterestModal();
        }
    });

    // Quick fix for sidebar
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Quick fix loaded');
        
        // Add click events to sidebar
        document.querySelectorAll('.vc-nav-item[data-tab]').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('data-tab');
                console.log('Quick fix: Switching to', tabId);
                
                // Switch tab
                document.querySelectorAll('.vc-tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                    if(btn.getAttribute('data-tab') === tabId) {
                        btn.classList.add('active');
                    }
                });
                
                document.querySelectorAll('.vc-tab-content').forEach(content => {
                    content.classList.remove('active');
                    if(content.id === tabId) {
                        content.classList.add('active');
                    }
                });
                
                // Update sidebar active state
                document.querySelectorAll('.vc-nav-item').forEach(nav => {
                    nav.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
    });
    </script>
</body>
</html>