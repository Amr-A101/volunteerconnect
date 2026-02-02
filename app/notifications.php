<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

$user = current_user();
if (!$user) {
    header("Location: login.php");
    exit;
}

// Get counts for tabs
$counts = [
    'all' => 0,
    'unread' => 0,
    'read' => 0
];

// Count all notifications
$stmt = $dbc->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
    FROM notifications
    WHERE user_id = ?
      AND is_deleted = 0
");
$stmt->bind_param("i", $user['user_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result) {
    $counts['all'] = $result['total'];
    $counts['unread'] = $result['unread'] ?: 0;
    $counts['read'] = $result['read_count'] ?: 0;
}

// Get current tab from query parameter
$current_tab = $_GET['tab'] ?? 'all';
$valid_tabs = ['all', 'unread', 'read'];
if (!in_array($current_tab, $valid_tabs)) {
    $current_tab = 'all';
}

// Build query based on tab
$query_conditions = "WHERE user_id = ? AND is_deleted = 0";
$params = [$user['user_id']];
$param_types = "i";

if ($current_tab === 'unread') {
    $query_conditions .= " AND is_read = 0";
} elseif ($current_tab === 'read') {
    $query_conditions .= " AND is_read = 1";
}

$stmt = $dbc->prepare("
    SELECT 
        notification_id,
        title,
        message,
        type,
        action_url,
        context_type,
        context_id,
        created_at,
        is_read,
        is_dismissible
    FROM notifications
    $query_conditions
    ORDER BY created_at DESC
");

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = "Notifications";
require_once __DIR__ . "/views/layout/header.php";
?>

<style>
.vc-container-page {
    margin: 50px auto;
    max-width: 1000px;
}

/* Notification Page Styles */
.vc-page-header {
    margin-bottom: 2rem;
}

.vc-page-header h1 {
    margin: 0 0 0.5rem 0;
    color: #2c3e50;
}

.vc-page-header p {
    color: #7f8c8d;
    margin: 0;
}

/* Card Styling */
.vc-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

/* Header with Tabs and Actions */
.vc-notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid #eee;
    flex-wrap: wrap;
    gap: 1rem;
}

/* Tabs */
.vc-tabs {
    display: flex;
    gap: 1px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 4px;
}

.vc-tab {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: #6c757d;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    font-weight: 500;
}

.vc-tab:hover {
    background: #e9ecef;
    color: #495057;
}

.vc-tab.active {
    background: #007bff;
    color: white;
}

.vc-badge {
    background: rgba(255,255,255,0.2);
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Notification Item */
.vc-notif-list {
    max-height: 600px;
    overflow-y: auto;
}

.vc-notif-item {
    display: flex;
    align-items: flex-start;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.3s ease;
    position: relative;
    cursor: pointer;
}

.vc-notif-item.unread {
    background: #f8fdff;
    border-left: 3px solid #007bff;
}

.vc-notif-item.read {
    opacity: 0.9;
}

.vc-notif-item:hover {
    background: #f9f9f9;
}

.vc-notif-item.unread:hover {
    background: #f0f7ff;
}

/* Notification Icon */
.vc-notif-icon {
    margin-right: 1rem;
    margin-top: 0.25rem;
    color: #007bff;
    font-size: 1.25rem;
    width: 40px;
    text-align: center;
}

.vc-notif-type-success .vc-notif-icon { color: #28a745; }
.vc-notif-type-warning .vc-notif-icon { color: #ffc107; }
.vc-notif-type-error .vc-notif-icon { color: #dc3545; }
.vc-notif-type-system .vc-notif-icon { color: #6c757d; }

/* Notification Content */
.vc-notif-content {
    flex: 1;
    min-width: 0;
}

.vc-notif-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.vc-notif-header h4 {
    margin: 0;
    font-size: 1rem;
    color: #2c3e50;
}

.vc-notif-time {
    font-size: 0.875rem;
    color: #95a5a6;
    white-space: nowrap;
}

.vc-notif-message {
    margin: 0 0 0.75rem 0;
    color: #34495e;
    line-height: 1.5;
}

.vc-notif-action-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #007bff;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: color 0.3s ease;
}

.vc-notif-action-link:hover {
    color: #0056b3;
    text-decoration: underline;
}

/* Notification Actions */
.vc-notif-item-actions {
    display: flex;
    gap: 0.5rem;
    margin-left: 1rem;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.vc-notif-item:hover .vc-notif-item-actions {
    opacity: 1;
}

/* Empty State */
.vc-empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #95a5a6;
}

.vc-empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.5;
}

.vc-empty-state h3 {
    margin: 0 0 0.5rem 0;
    color: #7f8c8d;
}

.vc-empty-state p {
    margin: 0;
}

/* Action Buttons Group */
.vc-notif-actions-group {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

/* Responsive */
@media (max-width: 768px) {
    .vc-notifications-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .vc-tabs {
        order: 1;
    }
    
    .vc-notif-actions-group {
        order: 2;
        justify-content: flex-start;
    }
    
    .vc-notif-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .vc-notif-item {
        flex-wrap: wrap;
    }
    
    .vc-notif-item-actions {
        opacity: 1;
        margin-left: 0;
        margin-top: 1rem;
        width: 100%;
        justify-content: flex-end;
    }
}

.vc-notif-context {
    margin-top: 0.5rem;
}

.vc-context-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: #f1f8ff;
    border-radius: 12px;
    color: #0366d6;
    font-size: 0.75rem;
    font-weight: 500;
}

.vc-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem 1.5rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    z-index: 1000;
    animation: slideIn 0.3s ease;
}

.vc-toast-success {
    border-left: 4px solid #28a745;
}

.vc-toast-error {
    border-left: 4px solid #dc3545;
}

.vc-toast-warning {
    border-left: 4px solid #ffc107;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.vc-notif-item {
    transition: all 0.3s ease;
}

.vc-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px 16px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    width: 100%;
}

.vc-btn-xs i {
    font-size: 12px;
}

/* Success button (mark as read) */
.vc-btn-success {
    background-color: #10b981; /* green */
    color: #fff;
}

.vc-btn-success:hover {
    background-color: #059669;
}

/* Danger button (delete) */
.vc-btn-danger {
    background-color: #ef4444; /* red */
    color: #fff;
}

.vc-btn-danger:hover {
    background-color: #dc2626;
}
</style>

<div class="vc-container-page">
    <div class="vc-page-header">
        <h1>Notifications</h1>
        <p>Stay updated with your recent activities</p>
    </div>

    <div class="vc-card">
        <div class="vc-notifications-header">
            <!-- Tabs -->
            <div class="vc-tabs">
                <a href="?tab=all" class="vc-tab <?= $current_tab === 'all' ? 'active' : '' ?>">
                    All
                    <span class="vc-badge"><?= $counts['all'] ?></span>
                </a>
                <a href="?tab=unread" class="vc-tab <?= $current_tab === 'unread' ? 'active' : '' ?>">
                    Unread
                    <span class="vc-badge"><?= $counts['unread'] ?></span>
                </a>
                <a href="?tab=read" class="vc-tab <?= $current_tab === 'read' ? 'active' : '' ?>">
                    Read
                    <span class="vc-badge"><?= $counts['read'] ?></span>
                </a>
            </div>

            <!-- Actions -->
            <div class="vc-notif-actions-group">
                <?php if ($current_tab === 'unread' && $counts['unread'] > 0): ?>
                    <button class="vc-btn vc-btn-sm vc-btn-success" onclick="markAllAsRead()">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                <?php endif; ?>
                
                <?php if (!empty($notifications)): ?>
                    <button class="vc-btn vc-btn-sm vc-btn-danger" onclick="clearAllNotifications('<?= $current_tab ?>')">
                        <i class="fas fa-trash"></i> Clear <?= ucfirst($current_tab) ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications List -->
        <?php if (!empty($notifications)): ?>
            <div class="vc-notif-list">
                <?php foreach ($notifications as $n): ?>
                    <div class="vc-notif-item <?= $n['is_read'] ? 'read' : 'unread' ?> 
                         vc-notif-type-<?= $n['type'] ?? 'info' ?>"
                         data-id="<?= $n['notification_id'] ?>"
                         data-dismissible="<?= $n['is_dismissible'] ?? 1 ?>">
                        
                        <!-- Notification Icon based on type -->
                        <div class="vc-notif-icon">
                            <?php
                            $icons = [
                                'info' => 'fa-info-circle',
                                'success' => 'fa-check-circle',
                                'warning' => 'fa-exclamation-triangle',
                                'error' => 'fa-times-circle',
                                'system' => 'fa-cog'
                            ];
                            $icon = $icons[$n['type'] ?? 'info'] ?? 'fa-bell';
                            ?>
                            <i class="fas <?= $icon ?>"></i>
                        </div>

                        <!-- Notification Content -->
                        <div class="vc-notif-content">
                            <div class="vc-notif-header">
                                <h4><?= esc($n['title']) ?></h4>
                                <span class="vc-notif-time">
                                    <?= date('M d, g:i A', strtotime($n['created_at'])) ?>
                                </span>
                            </div>
                            
                            <p class="vc-notif-message"><?= esc($n['message']) ?></p>
                            
                            <!-- Context information -->
                            <?php if (!empty($n['context_type'])): ?>
                                <div class="vc-notif-context">
                                    <small class="vc-context-badge">
                                        <?= esc(ucfirst($n['context_type'])) ?>
                                        <?php if (!empty($n['context_id'])): ?>
                                            #<?= $n['context_id'] ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Action URL if exists -->
                            <?php if (!empty($n['action_url'])): ?>
                                <a href="<?= esc($n['action_url']) ?>" class="vc-notif-action-link">
                                    View details <i class="fas fa-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Notification Actions -->
                        <div class="vc-notif-item-actions">
                            <?php if (!$n['is_read']): ?>
                                <button class="vc-btn vc-btn-xs vc-btn-success" 
                                        onclick="markRead(<?= $n['notification_id'] ?>)"
                                        title="Mark as read">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                            
                            <?php if (!empty($n['is_dismissible'])): ?>
                                <button class="vc-btn vc-btn-xs vc-btn-danger" 
                                        onclick="deleteNotification(event, <?= $n['notification_id'] ?>)"
                                        title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="vc-empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>No notifications</h3>
                <p>You're all caught up! No <?= $current_tab ?> notifications to show.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . "/views/layout/footer.php"; ?>

<script>
// Base URL for API calls
const API_URL = '/volcon/app/notif.php';

// Show loading state
function showLoading(button) {
    if (button) {
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        button.disabled = true;
        return originalHTML;
    }
}

// Hide loading state
function hideLoading(button, originalHTML) {
    if (button && originalHTML) {
        button.innerHTML = originalHTML;
        button.disabled = false;
    }
}

// Show toast message
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `vc-toast vc-toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Mark single notification as read
function markRead(id) {
    const button = event?.target?.closest('button');
    const originalHTML = showLoading(button);
    
    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'mark_read',
            notification_id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(button, originalHTML);
        
        if (data.success) {
            showToast('Notification marked as read');
            
            // Update UI without reload
            const item = document.querySelector(`[data-id="${id}"]`);
            if (item) {
                item.classList.remove('unread');
                item.classList.add('read');
                
                // Remove mark as read button
                const markReadBtn = item.querySelector('.vc-btn-success');
                if (markReadBtn) markReadBtn.remove();
                
                // Update badge counts
                updateBadgeCounts(-1, 'unread');
                updateBadgeCounts(1, 'read');
            }
        } else {
            showToast(data.error || 'Failed to mark as read', 'error');
        }
    })
    .catch(error => {
        hideLoading(button, originalHTML);
        showToast('Network error. Please try again.', 'error');
    });
}

// Mark all as read
function markAllAsRead() {
    if (!confirm('Mark all notifications as read?')) return;
    
    const button = event?.target;
    const originalHTML = showLoading(button);
    
    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'mark_all_read'
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(button, originalHTML);
        
        if (data.success) {
            showToast('All notifications marked as read');
            
            // Update UI
            document.querySelectorAll('.vc-notif-item.unread').forEach(item => {
                item.classList.remove('unread');
                item.classList.add('read');
                
                // Remove mark as read buttons
                const markReadBtn = item.querySelector('.vc-btn-success');
                if (markReadBtn) markReadBtn.remove();
            });
            
            // Update badge counts
            const unreadCount = document.querySelectorAll('.vc-notif-item.unread').length;
            updateBadgeCounts(-unreadCount, 'unread');
            updateBadgeCounts(unreadCount, 'read');
            
            // If on unread tab, show empty state
            if (window.location.search.includes('tab=unread')) {
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            showToast('Failed to mark all as read', 'error');
        }
    })
    .catch(error => {
        hideLoading(button, originalHTML);
        showToast('Network error. Please try again.', 'error');
    });
}

// Delete single notification
function deleteNotification(event, id) {
    event.stopPropagation();
    
    const item = document.querySelector(`[data-id="${id}"]`);
    const isDismissible = item?.getAttribute('data-dismissible') === '1';
    
    if (!isDismissible) {
        showToast('This notification cannot be dismissed', 'warning');
        return;
    }
    
    if (!confirm('Delete this notification?')) return;

    const button = event.target.closest('button');
    const originalHTML = showLoading(button);
    
    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'delete',
            notification_id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(button, originalHTML);
        
        if (data.success) {
            showToast('Notification deleted');
            
            // Animate removal
            if (item) {
                const wasUnread = item.classList.contains('unread');
                const wasRead = item.classList.contains('read');
                
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    item.remove();
                    
                    // Update badge counts
                    if (wasUnread) {
                        updateBadgeCounts(-1, 'unread');
                        updateBadgeCounts(-1, 'all');
                    } else if (wasRead) {
                        updateBadgeCounts(-1, 'read');
                        updateBadgeCounts(-1, 'all');
                    }
                    
                    // Check if list is now empty
                    if (!document.querySelector('.vc-notif-item')) {
                        showEmptyState();
                    }
                }, 300);
            }
        } else {
            showToast('Failed to delete notification', 'error');
        }
    })
    .catch(error => {
        hideLoading(button, originalHTML);
        showToast('Network error. Please try again.', 'error');
    });
}

// Clear all notifications based on current tab
function clearAllNotifications(tab) {
    const message = tab === 'all' 
        ? 'Clear all notifications? This action cannot be undone.'
        : `Clear all ${tab} notifications? This action cannot be undone.`;
    
    if (!confirm(message)) return;

    const button = event?.target;
    const originalHTML = showLoading(button);
    
    // Get IDs to delete
    const itemsToDelete = [];
    if (tab === 'all') {
        itemsToDelete.push(...document.querySelectorAll('.vc-notif-item'));
    } else if (tab === 'unread') {
        itemsToDelete.push(...document.querySelectorAll('.vc-notif-item.unread'));
    } else if (tab === 'read') {
        itemsToDelete.push(...document.querySelectorAll('.vc-notif-item.read'));
    }
    
    // Filter only dismissible notifications
    const dismissibleItems = Array.from(itemsToDelete).filter(item => 
        item.getAttribute('data-dismissible') === '1'
    );
    
    if (dismissibleItems.length === 0) {
        hideLoading(button, originalHTML);
        showToast('No dismissible notifications to clear', 'warning');
        return;
    }
    
    // Delete each notification
    const deletePromises = dismissibleItems.map(item => {
        const id = item.getAttribute('data-id');
        return fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                notification_id: id
            })
        }).then(response => response.json());
    });
    
    Promise.all(deletePromises)
        .then(results => {
            hideLoading(button, originalHTML);
            
            const successful = results.filter(r => r.success).length;
            showToast(`Cleared ${successful} notification(s)`);
            
            // Remove items with animation
            dismissibleItems.forEach((item, index) => {
                if (results[index]?.success) {
                    setTimeout(() => {
                        item.style.opacity = '0';
                        item.style.transform = 'translateX(-20px)';
                        setTimeout(() => item.remove(), 300);
                    }, index * 50);
                }
            });
            
            // Update badge counts after animations
            setTimeout(() => {
                updateBadgeCounts(-successful, tab);
                updateBadgeCounts(-successful, 'all');
                
                // Show empty state if no items left
                if (!document.querySelector('.vc-notif-item')) {
                    setTimeout(() => showEmptyState(), 500);
                }
            }, dismissibleItems.length * 50 + 300);
        })
        .catch(error => {
            hideLoading(button, originalHTML);
            showToast('Error clearing notifications', 'error');
        });
}

// Update badge counts
function updateBadgeCounts(change, tab) {
    const tabElement = document.querySelector(`.vc-tab[href*="tab=${tab}"] .vc-badge`);
    if (tabElement) {
        let currentCount = parseInt(tabElement.textContent) || 0;
        let newCount = Math.max(0, currentCount + change);
        tabElement.textContent = newCount;
    }
}

// Show empty state
function showEmptyState() {
    const currentTab = new URLSearchParams(window.location.search).get('tab') || 'all';
    const emptyStateHTML = `
        <div class="vc-empty-state">
            <i class="fas fa-bell-slash"></i>
            <h3>No notifications</h3>
            <p>You're all caught up! No ${currentTab} notifications to show.</p>
        </div>
    `;
    
    const notifList = document.querySelector('.vc-notif-list');
    if (notifList) {
        notifList.innerHTML = emptyStateHTML;
    }
}

// Click anywhere on notification to mark as read (if unread)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.vc-notif-item.unread').forEach(item => {
        item.addEventListener('click', function(e) {
            // Don't trigger if clicking on action buttons or links
            if (e.target.closest('.vc-notif-item-actions') || 
                e.target.closest('a')) return;
            
            const id = this.getAttribute('data-id');
            markRead(id);
        });
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Alt + R to mark all as read
    if (e.altKey && e.key === 'r') {
        e.preventDefault();
        if (document.querySelector('.vc-notif-item.unread')) {
            markAllAsRead();
        }
    }
});
</script>
