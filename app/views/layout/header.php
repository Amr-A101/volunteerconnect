<?php
// /volcon/app/views/layout/header.php

require_once __DIR__ . "/../../core/flash.php";
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';

function esc($v) { return htmlspecialchars($v, ENT_QUOTES); }

$dbc = $GLOBALS['dbc'] ?? null;
$user = current_user();
$role = $user['role'] ?? null;

$notifications = [];
$unread_count = 0;

if ($user) {
    // unread count
    $stmt = $dbc->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0 AND is_deleted = 0
    ");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $stmt->bind_result($unread_count);
    $stmt->fetch();
    $stmt->close();

    // latest notifications
    $stmt = $dbc->prepare("
        SELECT notification_id, title, message, type, action_url, created_at, is_read
        FROM notifications
        WHERE user_id = ? AND is_deleted = 0
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


$display_name = 'User';
$profile_pic  = null;
$initials     = 'U';

$flash = get_flash();
$alert_msg = "";
$alert_type = "";
$alert_timeout = 10;

if ($flash) {
    $alert_msg = $flash['message'];
    $alert_type = $flash['type'];
}

if (!isset($page_title)) $page_title = "Volunteer Connect";

// Load role-based CSS
$css_file = "/volcon/assets/css/layout/header_guest.css";
if ($role === 'vol')
    $css_file = "/volcon/assets/css/layout/header_vol.css";
    $profile_pic  = $user['profile_picture'] ?? null;
if ($role === 'org')
    $css_file = "/volcon/assets/css/layout/header_org.css";
    $profile_pic  = $user['profile_picture'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . " - Volunteer Connect"); ?></title>

    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">

    <link rel="stylesheet" href="<?php echo $css_file; ?>">
    <link rel="stylesheet" href="/volcon/assets/css/components/alerts.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>
<body>

    <header class="vc-header">
        <div class="vc-container">
            <!-- Logo -->
            <a href="/volcon" class="vc-logo">
                <img src="/volcon/assets/res/logo/volcon-logo.png" alt="Volunteer Connect">
            </a>

            <!-- Search Bar (Desktop) -->
            <div class="vc-search-bar">
                <div class="vc-search-wrapper">
                    <input type="text" 
                        id="global-search" 
                        class="vc-search-input" 
                        placeholder="Search opportunities..."
                        autocomplete="off"
                        list="search-history">
                    <datalist id="search-history"></datalist>
                    <button onclick="performGlobalSearch()" class="vc-search-btn">
                        <i class="fa fa-search"></i>
                    </button>
                </div>
            </div>

            <!-- Desktop Navigation -->
            <nav class="vc-nav-desktop">
                <?php if (!$role): ?>
                    <!-- Guest Navigation -->
                    <a href="/volcon/app/login.php" class="vc-nav-link">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </a>
                    <a href="/volcon/app/signup.php" class="vc-nav-link vc-nav-cta">
                        <i class="fas fa-user-plus"></i> Join Now
                    </a>
                
                <?php elseif ($role === 'vol'): ?>
                    <!-- Volunteer Navigation -->
                    <a href="/volcon/app/browse_opportunities.php" class="vc-nav-link">
                        <i class="fas fa-search"></i> Browse
                    </a>
                    <a href="/volcon/app/my_applications.php" class="vc-nav-link">
                        <i class="fas fa-clipboard-list"></i> Applications
                    </a>
                    <a href="/volcon/app/dashboard_vol.php" class="vc-nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <div class="vc-notification-wrapper">
                        <button class="vc-notification-btn notifToggle">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="vc-notif-badge" id="notifCount">
                                    <?= (int)$unread_count ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <div class="vc-notification-panel notifPanel">
                            <div class="vc-notif-header">
                                <strong>Notifications</strong>
                                <button class="vc-mark-all" onclick="markAllRead()">Mark all read</button>
                            </div>

                            <div class="vc-notif-list" id="notifList">
                                <?php if (empty($notifications)): ?>
                                    <div class="vc-notif-empty">
                                        No notifications yet
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $n): ?>
                                        <div class="vc-notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                                            onclick="openNotification(
                                                <?= (int)$n['notification_id'] ?>,
                                                '<?= esc($n['action_url'] ?? '') ?>'
                                            )">

                                            <div class="vc-notif-icon type-<?= esc($n['type']) ?>">
                                                <i class="fas fa-info-circle"></i>
                                            </div>

                                            <div class="vc-notif-content">
                                                <strong><?= esc($n['title']) ?></strong>
                                                <p><?= esc($n['message']) ?></p>
                                                <small><?= date('M d, g:i A', strtotime($n['created_at'])) ?></small>
                                            </div>

                                            <button class="vc-notif-delete"
                                                onclick="deleteNotification(event, <?= (int)$n['notification_id'] ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="vc-notif-footer">
                                <a href="/volcon/app/notifications.php">View all</a>
                            </div>
                        </div>
                    </div>
                    <div class="vc-user-dropdown">
                        <button class="vc-user-btn">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="vc-dropdown-menu">
                            <a href="/volcon/app/profile_vol.php">
                                <i class="fas fa-user"></i> My Profile
                            </a>
                            <a href="/volcon/app/my_participation.php">
                                <i class="fas fa-clipboard-list"></i> My Participation
                            </a>
                            <a href="/volcon/app/logout.php" onclick="return confirm('Confirm logout?')">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                
                <?php elseif ($role === 'org'): ?>
                    <!-- Organization Navigation -->
                    <a href="/volcon/app/post_opportunity.php" class="vc-nav-link">
                        <i class="fas fa-plus-circle"></i> Post Opportunity
                    </a>
                    <a href="/volcon/app/applicants_manager.php" class="vc-nav-link">
                        <i class="fas fa-users"></i> Applicants
                    </a>
                    <a href="/volcon/app/dashboard_org.php" class="vc-nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <div class="vc-notification-wrapper">
                        <button class="vc-notification-btn notifToggle">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="vc-notif-badge" id="notifCount">
                                    <?= (int)$unread_count ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <div class="vc-notification-panel notifPanel">
                            <div class="vc-notif-header">
                                <strong>Notifications</strong>
                                <button class="vc-mark-all" onclick="markAllRead()">Mark all read</button>
                            </div>

                            <div class="vc-notif-list" id="notifList">
                                <?php if (empty($notifications)): ?>
                                    <div class="vc-notif-empty">
                                        No notifications yet
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $n): ?>
                                        <div class="vc-notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                                            onclick="openNotification(
                                                <?= (int)$n['notification_id'] ?>,
                                                '<?= esc($n['action_url'] ?? '') ?>'
                                            )">

                                            <div class="vc-notif-icon type-<?= esc($n['type']) ?>">
                                                <i class="fas fa-info-circle"></i>
                                            </div>

                                            <div class="vc-notif-content">
                                                <strong><?= esc($n['title']) ?></strong>
                                                <p><?= esc($n['message']) ?></p>
                                                <small><?= date('M d, g:i A', strtotime($n['created_at'])) ?></small>
                                            </div>

                                            <button class="vc-notif-delete"
                                                onclick="deleteNotification(event, <?= (int)$n['notification_id'] ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="vc-notif-footer">
                                <a href="/volcon/app/notifications.php">View all</a>
                            </div>
                        </div>
                    </div>
                    <div class="vc-user-dropdown">
                        <button class="vc-user-btn">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($user['org_name'] ?? 'Organization'); ?>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="vc-dropdown-menu">
                            <a href="/volcon/app/profile_org.php">
                                <i class="fas fa-building"></i> My Profile
                            </a>
                            <a href="/volcon/app/my_opportunities.php">
                                <i class="fas fa-list-alt"></i> My Opportunities
                            </a>
                            <!-- <a href="/volcon/app/settings.php">
                                <i class="fas fa-cog"></i> Settings
                            </a> -->
                            <a href="/volcon/app/logout.php" onclick="return confirm('Confirm logout?')">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </nav>

            <!-- Mobile Menu Toggle -->
            <button class="vc-menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Mobile Navigation Overlay -->
        <div class="vc-mobile-overlay" id="mobileOverlay">
            <div class="vc-mobile-header">
                <button class="vc-close-menu" id="closeMenu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <nav class="vc-nav-mobile">
                <?php if (!$role): ?>
                    <!-- Guest Mobile Navigation -->
                    <a href="/volcon/app/login.php" class="vc-mobile-link">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </a>
                    <a href="/volcon/app/signup.php" class="vc-mobile-link">
                        <i class="fas fa-user-plus"></i> Join Now
                    </a>
                
                <?php elseif ($role === 'vol'): ?>
                    <!-- Volunteer Mobile Navigation -->
                    <div class="vc-mobile-user">
                        <div class="vc-avatar">
                            <?php if (!empty($profile_pic)): ?>
                                <img 
                                    src="<?= htmlspecialchars($profile_pic) ?>" 
                                    alt="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>" 
                                    class="vc-avatar-img"
                                >
                            <?php else: ?>
                            <?php echo strtoupper(substr($user['first_name'] ?? 'U', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="vc-user-info">
                            <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                            <p>Volunteer</p>
                        </div>
                    </div>
                    <a href="/volcon/app/browse_opportunities.php" class="vc-mobile-link">
                        <i class="fas fa-search"></i> Browse Opportunities
                    </a>
                    <a href="/volcon/app/dashboard_vol.php" class="vc-mobile-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="/volcon/app/my_applications.php" class="vc-mobile-link">
                        <i class="fas fa-clipboard-list"></i> My Applications
                    </a>
                    <a href="/volcon/app/profile_vol.php" class="vc-mobile-link">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                    <!-- <a href="/volcon/app/settings.php" class="vc-mobile-link">
                        <i class="fas fa-cog"></i> Settings
                    </a> -->
                    <a href="/volcon/app/logout.php" class="vc-mobile-link vc-logout" onclick="return confirm('Confirm logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                
                <?php elseif ($role === 'org'): ?>
                    <!-- Organization Mobile Navigation -->
                    <div class="vc-mobile-user">
                        <div class="vc-avatar">
                            <?php if (!empty($profile_pic)): ?>
                                <img 
                                    src="<?= htmlspecialchars($profile_pic) ?>" 
                                    alt="<?= htmlspecialchars($user['org_name']) ?>" 
                                    class="vc-avatar-img"
                                >
                            <?php else: ?>
                            <?php echo strtoupper(substr($user['org_name'] ?? 'O', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="vc-user-info">
                            <h4><?php echo htmlspecialchars($user['org_name'] ?? 'Organization'); ?></h4>
                            <p>Organization</p>
                        </div>
                    </div>
                    <a href="/volcon/app/post_opportunity.php" class="vc-mobile-link">
                        <i class="fas fa-plus-circle"></i> Post Opportunity
                    </a>
                    <a href="/volcon/app/applicants_manager.php" class="vc-mobile-link">
                        <i class="fas fa-users"></i> Manage Applicants
                    </a>
                    <a href="/volcon/app/dashboard_org.php" class="vc-mobile-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="/volcon/app/my_opportunities.php" class="vc-mobile-link">
                        <i class="fas fa-list-alt"></i> My Opportunities
                    </a>
                    <a href="/volcon/app/profile_org.php" class="vc-mobile-link">
                        <i class="fas fa-building"></i> My Profile
                    </a>
                    <!-- <a href="/volcon/app/settings.php" class="vc-mobile-link">
                        <i class="fas fa-cog"></i> Settings
                    </a> -->
                    <a href="/volcon/app/logout.php" class="vc-mobile-link vc-logout" onclick="return confirm('Confirm logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    
    <?php include __DIR__ . "/../components/alerts.php"; ?>

    <script>
        // Notification Panel Toggle
        document.querySelectorAll('.notifToggle').forEach((toggle, index) => {
            const panel = document.querySelectorAll('.notifPanel')[index];

            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.toggle('active');
            });

            panel.addEventListener('click', (e) => e.stopPropagation());

            document.addEventListener('click', () => {
                panel.classList.remove('active');
            });
        });

        function openNotification(id, url) {
            fetch('/volcon/app/notif.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'mark_read',
                    notification_id: id
                })
            }).then(() => decrementNotifCount())
            .finally(() => {
                if (url) window.location.href = url;
            });
        }

        function markAllRead() {
            fetch('/volcon/app/notif.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'mark_all_read'
                })
            }).then(() => location.reload());
        }

        function decrementNotifCount() {
            const badge = document.getElementById('notifCount');
            if (!badge) return;

            let count = parseInt(badge.textContent, 10) || 0;
            count--;

            if (count <= 0) {
                badge.remove();
            } else {
                badge.textContent = count;
            }
        }

        function deleteNotification(e, id) {
            e.stopPropagation();

            fetch('/volcon/app/notif.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'delete',
                    notification_id: id
                })
            }).then(() => location.reload());
        }


        // Mobile Menu Toggle
        const menuToggle = document.getElementById('menuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const closeMenu = document.getElementById('closeMenu');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                mobileOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }
        
        if (closeMenu) {
            closeMenu.addEventListener('click', () => {
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
        
        // Close menu when clicking outside
        mobileOverlay.addEventListener('click', (e) => {
            if (e.target === mobileOverlay) {
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        // Search functionality
        function loadSearchHistory() {
            const history = JSON.parse(localStorage.getItem('searchHistory') || '[]');
            const datalist = document.getElementById('search-history');
            
            datalist.innerHTML = '';
            history.forEach(term => {
                const option = document.createElement('option');
                option.value = term;
                datalist.appendChild(option);
            });
        }

        function saveToHistory(keyword) {
            let history = JSON.parse(localStorage.getItem('searchHistory') || '[]');
            history = history.filter(term => term.toLowerCase() !== keyword.toLowerCase());
            history.unshift(keyword);
            history = history.slice(0, 10);
            localStorage.setItem('searchHistory', JSON.stringify(history));
            loadSearchHistory();
        }

        function performGlobalSearch() {
            const input = document.getElementById('global-search');
            const keyword = input.value.trim();
            
            if (keyword) {
                saveToHistory(keyword);
                window.location.href = `/volcon/app/browse_opportunities.php?q=${encodeURIComponent(keyword)}`;
            } else {
                window.location.href = '/volcon/app/browse_opportunities.php';
            }
        }

        // Initialize search
        document.getElementById('global-search')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performGlobalSearch();
            }
        });

        document.addEventListener('DOMContentLoaded', loadSearchHistory);
        
        // User dropdown functionality
        const userDropdowns = document.querySelectorAll('.vc-user-dropdown');
        userDropdowns.forEach(dropdown => {
            const btn = dropdown.querySelector('.vc-user-btn');
            const menu = dropdown.querySelector('.vc-dropdown-menu');
            
            btn?.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.classList.toggle('active');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', () => {
                menu.classList.remove('active');
            });
        });
    </script>

    <div class="vc-page-content">