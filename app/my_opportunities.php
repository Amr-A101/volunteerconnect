<?php
// my_opportunities.php (Improved)

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';

require_role('org');

$user = current_user();
$org_id = (int)$user['user_id'];

if ($user['role'] !== 'org') {
    die("Forbidden");
}

/* =========================
   AUTO STATUS TRANSITIONS
========================= */
require_once __DIR__ . '/core/auto_opp_trans.php';
runOpportunityAutoTransitions($dbc, $org_id);

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_desc';

// Validate sort parameter
$valid_sorts = [
    'created_desc' => 'o.created_at DESC',
    'created_asc' => 'o.created_at ASC',
    'title_asc' => 'o.title ASC',
    'title_desc' => 'o.title DESC',
    'start_date' => 'o.start_date ASC',
    'end_date' => 'o.end_date DESC',
    'applicants_desc' => 'total_applications DESC'
];

$sort_order = $valid_sorts[$sort_by] ?? 'o.created_at DESC';

// Build filter conditions
$filter_conditions = ["o.org_id = ?"];
$params = [$org_id];
$param_types = "i";

// Filter by status
if ($filter_status !== 'all' && $filter_status !== '') {
    if ($filter_status === 'active') {
        $filter_conditions[] = "o.status IN ('open', 'ongoing', 'closed')";
    } elseif ($filter_status === 'inactive') {
        $filter_conditions[] = "o.status IN ('completed', 'canceled', 'suspended')";
    } else {
        $filter_conditions[] = "o.status = ?";
        $params[] = $filter_status;
        $param_types .= "s";
    }
}

// Search filter
if (!empty($search_query)) {
    $filter_conditions[] = "(o.title LIKE ? OR o.brief_summary LIKE ? OR o.city LIKE ?)";
    $search_term = "%{$search_query}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sss";
}

// Exclude deleted opportunities
$filter_conditions[] = "o.status != 'deleted'";

// Get opportunities with counts (simplified query for better performance)
$where_clause = implode(" AND ", $filter_conditions);
$query = "
    SELECT 
        o.*,
        COUNT(DISTINCT a.application_id) as total_applications,
        SUM(CASE WHEN a.status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
        SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN a.status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted_count,
        COUNT(DISTINCT p.participation_id) as total_participations,
        SUM(CASE WHEN p.status = 'attended' THEN 1 ELSE 0 END) as attended_count,
        AVG(r.rating) as avg_rating,
        COUNT(DISTINCT r.review_id) as review_count
    FROM opportunities o
    LEFT JOIN applications a ON o.opportunity_id = a.opportunity_id
    LEFT JOIN participation p ON o.opportunity_id = p.opportunity_id
    LEFT JOIN reviews r ON o.opportunity_id = r.opportunity_id AND r.reviewee_type = 'organization'
    WHERE {$where_clause}
    GROUP BY o.opportunity_id
    ORDER BY {$sort_order}
";

$stmt = $dbc->prepare($query);

// Bind parameters dynamically
if (count($params) > 1) {
    $stmt->bind_param($param_types, ...$params);
} else {
    $stmt->bind_param($param_types, $params[0]);
}

$stmt->execute();
$result = $stmt->get_result();
$opportunities = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get selected opportunity details
$selected_opp = null;
$selected_opp_id = $_GET['view'] ?? ($opportunities[0]['opportunity_id'] ?? null);

if ($selected_opp_id) {
    // Get full opportunity details - FIXED VERSION
    $detail_query = "
        SELECT o.*,
               org.name as org_name,
               org.profile_picture as org_logo  -- Changed from logo_url to profile_picture
        FROM opportunities o
        JOIN organizations org ON o.org_id = org.org_id
        WHERE o.opportunity_id = ? AND o.org_id = ?
    ";
    $stmt = $dbc->prepare($detail_query);
    $stmt->bind_param("ii", $selected_opp_id, $org_id);
    $stmt->execute();
    $selected_opp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($selected_opp) {
        // Get applications summary
        $app_stmt = $dbc->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM applications 
            WHERE opportunity_id = ?
            GROUP BY status
        ");
        $app_stmt->bind_param("i", $selected_opp_id);
        $app_stmt->execute();
        $applications_summary = $app_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $selected_opp['applications_summary'] = [];
        foreach ($applications_summary as $app) {
            $selected_opp['applications_summary'][$app['status']] = $app['count'];
        }
        $app_stmt->close();
        
        // Get participation summary
        $part_stmt = $dbc->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(hours_worked) as total_hours
            FROM participation 
            WHERE opportunity_id = ?
            GROUP BY status
        ");
        $part_stmt->bind_param("i", $selected_opp_id);
        $part_stmt->execute();
        $participation_summary = $part_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $selected_opp['participation_summary'] = [];
        $selected_opp['total_hours_worked'] = 0;
        foreach ($participation_summary as $part) {
            $selected_opp['participation_summary'][$part['status']] = $part['count'];
            $selected_opp['total_hours_worked'] += $part['total_hours'] ?? 0;
        }
        $part_stmt->close();
        
        // Get skills
        $skill_stmt = $dbc->prepare("
            SELECT s.skill_name, sc.category_name
            FROM opportunity_skills os
            JOIN skills s ON os.skill_id = s.skill_id
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE os.opportunity_id = ?
            ORDER BY sc.category_name, s.skill_name
        ");
        $skill_stmt->bind_param("i", $selected_opp_id);
        $skill_stmt->execute();
        $selected_opp['skills'] = $skill_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $skill_stmt->close();
        
        // Get interests
        $interest_stmt = $dbc->prepare("
            SELECT i.interest_name, ic.category_name
            FROM opportunity_interests oi
            JOIN interests i ON oi.interest_id = i.interest_id
            LEFT JOIN interest_categories ic ON i.category_id = ic.category_id
            WHERE oi.opportunity_id = ?
            ORDER BY ic.category_name, i.interest_name
        ");
        $interest_stmt->bind_param("i", $selected_opp_id);
        $interest_stmt->execute();
        $selected_opp['interests'] = $interest_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $interest_stmt->close();
        
        // Get images
        $image_stmt = $dbc->prepare("
            SELECT image_url
            FROM opportunity_images 
            WHERE opportunity_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $image_stmt->bind_param("i", $selected_opp_id);
        $image_stmt->execute();
        $selected_opp['images'] = $image_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $image_stmt->close();
        
        // Get contacts
        $contact_stmt = $dbc->prepare("
            SELECT contact_name, contact_email, contact_phone, is_primary
            FROM opportunity_contacts 
            WHERE opportunity_id = ?
            ORDER BY is_primary DESC, contact_name ASC
        ");
        $contact_stmt->bind_param("i", $selected_opp_id);
        $contact_stmt->execute();
        $selected_opp['contacts'] = $contact_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $contact_stmt->close();
        
        // Get available actions based on current status
        $selected_opp['available_actions'] = getAvailableActions($selected_opp['status'], $selected_opp['total_applications'] ?? 0);
    }
}

// Get statistics for filter tabs
$stats_query = "
    SELECT 
        SUM(CASE WHEN status IN ('open', 'ongoing', 'closed') THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status IN ('completed', 'canceled', 'suspended') THEN 1 ELSE 0 END) as inactive_count,
        COUNT(*) as total_count
    FROM opportunities 
    WHERE org_id = ? AND status != 'deleted'
";
$stats_stmt = $dbc->prepare($stats_query);
$stats_stmt->bind_param("i", $org_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// Helper function to get available actions
function getAvailableActions($status, $total_applications = 0) {
    $actions = [];
    
    switch ($status) {
        case 'draft':
            $actions[] = ['name' => 'publish', 'label' => 'Publish', 'type' => 'success', 'icon' => 'fa-check-circle'];
            $actions[] = ['name' => 'delete', 'label' => 'Delete', 'type' => 'danger', 'icon' => 'fa-trash', 'confirm' => true];
            break;
            
        case 'open':
            $actions[] = ['name' => 'close', 'label' => 'Close', 'type' => 'warning', 'icon' => 'fa-lock', 'confirm' => true];
            if ($total_applications === 0) {
                $actions[] = ['name' => 'delete', 'label' => 'Delete', 'type' => 'danger', 'icon' => 'fa-trash', 'confirm' => true];
            }
            break;
            
        case 'closed':
            $actions[] = ['name' => 'reopen', 'label' => 'Reopen', 'type' => 'success', 'icon' => 'fa-lock-open'];
            $actions[] = ['name' => 'cancel', 'label' => 'Cancel', 'type' => 'warning', 'icon' => 'fa-times-circle', 'confirm' => true];
            break;
            
        case 'ongoing':
            $actions[] = ['name' => 'complete', 'label' => 'Complete', 'type' => 'success', 'icon' => 'fa-flag-checkered'];
            break;
            
        case 'completed':
            $actions[] = ['name' => 'archive', 'label' => 'Archive', 'type' => 'info', 'icon' => 'fa-archive'];
            break;
    }
    
    // Always available actions
    $actions[] = ['name' => 'edit', 'label' => 'Edit', 'type' => 'primary', 'icon' => 'fa-edit'];
    $actions[] = ['name' => 'view', 'label' => 'View', 'type' => 'secondary', 'icon' => 'fa-eye'];
    
    return $actions;
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    $classes = [
        'draft' => 'vc-status-draft',
        'open' => 'vc-status-open',
        'closed' => 'vc-status-closed',
        'ongoing' => 'vc-status-ongoing',
        'completed' => 'vc-status-completed',
        'canceled' => 'vc-status-canceled',
        'suspended' => 'vc-status-suspended'
    ];
    return $classes[$status] ?? 'vc-status-default';
}

$page_title = "My Opportunities";
require_once __DIR__ . '/views/layout/header.php';
?>

<link rel="stylesheet" href="/volcon/assets/css/my_opportunities.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="vc-opportunities-container">
    <!-- Header Section -->
    <div class="vc-opp-header">
        <div class="vc-opp-header-content">
            <div class="vc-opp-header-main">
                <h1><i class="fas fa-tasks"></i> My Opportunities</h1>
                <p class="vc-opp-subtitle">Manage and track all your volunteer opportunities</p>
            </div>
            <div class="vc-opp-header-actions">
                <a href="dashboard_org.php" class="vc-btn vc-btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="post_opportunity.php" class="vc-btn vc-btn-primary">
                    <i class="fas fa-plus"></i> Create New
                </a>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="vc-opp-stats">
            <div class="vc-stat-card">
                <div class="vc-stat-icon">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="vc-stat-info">
                    <div class="vc-stat-value"><?= $stats['total_count'] ?? 0 ?></div>
                    <div class="vc-stat-label">Total Opportunities</div>
                </div>
            </div>
            
            <div class="vc-stat-card vc-stat-active">
                <div class="vc-stat-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="vc-stat-info">
                    <div class="vc-stat-value"><?= $stats['active_count'] ?? 0 ?></div>
                    <div class="vc-stat-label">Active</div>
                </div>
            </div>
            
            <div class="vc-stat-card vc-stat-applicants">
                <div class="vc-stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="vc-stat-info">
                    <div class="vc-stat-value">
                        <?= array_sum(array_column($opportunities, 'total_applications')) ?>
                    </div>
                    <div class="vc-stat-label">Total Applicants</div>
                </div>
            </div>
            
            <div class="vc-stat-card vc-stat-participation">
                <div class="vc-stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="vc-stat-info">
                    <div class="vc-stat-value">
                        <?= array_sum(array_column($opportunities, 'attended_count')) ?>
                    </div>
                    <div class="vc-stat-label">Attended</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="vc-opp-main">
        <!-- Left Panel: Opportunities List -->
        <div class="vc-opp-list-panel">
            <!-- Filters -->
            <div class="vc-opp-filters">
                <form method="get" class="vc-filter-form">
                    <div class="vc-filter-group">
                        <div class="vc-search-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   name="search" 
                                   value="<?= htmlspecialchars($search_query) ?>" 
                                   placeholder="Search opportunities...">
                        </div>
                        
                        <div class="vc-filter-row">
                            <select name="status" class="vc-select" onchange="this.form.submit()">
                                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="open" <?= $filter_status === 'open' ? 'selected' : '' ?>>Open</option>
                                <option value="closed" <?= $filter_status === 'closed' ? 'selected' : '' ?>>Closed</option>
                                <option value="ongoing" <?= $filter_status === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                            
                            <select name="sort" class="vc-select" onchange="this.form.submit()">
                                <option value="created_desc" <?= $sort_by === 'created_desc' ? 'selected' : '' ?>>Newest First</option>
                                <option value="created_asc" <?= $sort_by === 'created_asc' ? 'selected' : '' ?>>Oldest First</option>
                                <option value="title_asc" <?= $sort_by === 'title_asc' ? 'selected' : '' ?>>Title A-Z</option>
                                <option value="start_date" <?= $sort_by === 'start_date' ? 'selected' : '' ?>>Start Date</option>
                                <option value="applicants_desc" <?= $sort_by === 'applicants_desc' ? 'selected' : '' ?>>Most Applicants</option>
                            </select>
                            
                            <button type="submit" class="vc-btn vc-btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            
                            <a href="my_opportunities.php" class="vc-btn vc-btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- List Container with Scroll -->
            <div class="vc-opp-list-container">
                <?php
                // Pagination logic
                $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                $per_page = 20; // Show 20 opportunities per page
                $total_opportunities = count($opportunities);
                $total_pages = ceil($total_opportunities / $per_page);
                
                // Slice opportunities for current page
                $offset = ($page - 1) * $per_page;
                $paginated_opportunities = array_slice($opportunities, $offset, $per_page);
                ?>
                
                <div class="vc-opp-list">
                    <?php if (empty($paginated_opportunities)): ?>
                        <div class="vc-empty-list-state">
                            <i class="fas fa-folder-open"></i>
                            <p>No opportunities found</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($paginated_opportunities as $opp): ?>
                            <?php $is_selected = $selected_opp && $selected_opp['opportunity_id'] == $opp['opportunity_id']; ?>
                            <div class="vc-opp-item <?= $is_selected ? 'selected' : '' ?>" 
                                onclick="window.location='?view=<?= $opp['opportunity_id'] ?>&<?= http_build_query([
                                    'status' => $filter_status,
                                    'search' => $search_query,
                                    'sort' => $sort_by,
                                    'page' => $page // Keep current page
                                ]) ?>'">
                                
                                <div class="vc-opp-item-header">
                                    <h4 class="vc-opp-item-title">
                                        <?= htmlspecialchars($opp['title']) ?>
                                        <span class="vc-status-badge <?= getStatusBadgeClass($opp['status']) ?>">
                                            <?= ucfirst($opp['status']) ?>
                                        </span>
                                    </h4>
                                    <div class="vc-opp-item-stats">
                                        <span class="vc-stat">
                                            <i class="fas fa-users"></i>
                                            <?= $opp['accepted_count'] ?>/<?= $opp['number_of_volunteers'] ?: '∞' ?>
                                        </span>
                                        <span class="vc-stat">
                                            <i class="fas fa-file-alt"></i>
                                            <?= $opp['total_applications'] ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($opp['brief_summary']): ?>
                                <p class="vc-opp-item-summary">
                                    <?= htmlspecialchars(substr($opp['brief_summary'], 0, 80)) ?>
                                    <?= strlen($opp['brief_summary']) > 80 ? '...' : '' ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div class="vc-opp-pagination">
                <div class="vc-pagination-info">
                    Showing <?= $offset + 1 ?>-<?= min($offset + $per_page, $total_opportunities) ?> 
                    of <?= $total_opportunities ?> opportunities
                </div>
                
                <div class="vc-pagination-controls">
                    <!-- Previous Button -->
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                        class="vc-pagination-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <button class="vc-pagination-btn" disabled>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                        class="vc-pagination-btn <?= $i == $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <!-- Next Button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                        class="vc-pagination-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button class="vc-pagination-btn" disabled>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Panel: Opportunity Details -->
        <div class="vc-opp-details-panel">
            <?php if (!$selected_opp): ?>
                <div class="vc-empty-details">
                    <div class="vc-empty-icon">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <h3>Select an Opportunity</h3>
                    <p>Choose an opportunity from the list to view and manage its details</p>
                </div>
            <?php else: ?>
                <!-- Header -->
                <div class="vc-details-header">
                    <div class="vc-details-header-main">
                        <h2><?= htmlspecialchars($selected_opp['title']) ?></h2>
                        <div class="vc-details-meta">
                            <span class="vc-status-badge-lg <?= getStatusBadgeClass($selected_opp['status']) ?>">
                                <?= ucfirst($selected_opp['status']) ?>
                            </span>
                            <span class="vc-meta-text">
                                <i class="fas fa-hashtag"></i> ID: <?= $selected_opp['opportunity_id'] ?>
                            </span>
                            <span class="vc-meta-text">
                                <i class="fas fa-calendar"></i> Created: <?= date('M d, Y', strtotime($selected_opp['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="vc-details-actions">
                        <!-- Status Actions -->
                        <?php foreach ($selected_opp['available_actions'] as $action): ?>
                            <?php if ($action['name'] === 'edit'): ?>
                                <a href="edit_opportunity.php?id=<?= $selected_opp['opportunity_id'] ?>" 
                                   class="vc-btn vc-btn-<?= $action['type'] ?>">
                                    <i class="fas <?= $action['icon'] ?>"></i> <?= $action['label'] ?>
                                </a>
                            <?php elseif ($action['name'] === 'view'): ?>
                                <a href="view_opportunity.php?id=<?= $selected_opp['opportunity_id'] ?>" 
                                   target="_blank"
                                   class="vc-btn vc-btn-<?= $action['type'] ?>">
                                    <i class="fas <?= $action['icon'] ?>"></i> <?= $action['label'] ?>
                                </a>
                            <?php else: ?>
                                <a href="change_status.php?id=<?= $selected_opp['opportunity_id'] ?>&action=<?= $action['name'] ?>" 
                                   class="vc-btn vc-btn-<?= $action['type'] ?>"
                                   <?php if (isset($action['confirm'])): ?>
                                   onclick="return confirm('Are you sure you want to <?= $action['name'] ?> this opportunity?')"
                                   <?php endif; ?>>
                                    <i class="fas <?= $action['icon'] ?>"></i> <?= $action['label'] ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="vc-details-stats">
                    <div class="vc-quick-stat">
                        <div class="vc-quick-stat-value"><?= $selected_opp['total_applications'] ?? 0 ?></div>
                        <div class="vc-quick-stat-label">Applications</div>
                    </div>
                    
                    <div class="vc-quick-stat">
                        <div class="vc-quick-stat-value"><?= $selected_opp['accepted_count'] ?? 0 ?></div>
                        <div class="vc-quick-stat-label">Accepted</div>
                    </div>
                    
                    <div class="vc-quick-stat">
                        <div class="vc-quick-stat-value"><?= $selected_opp['attended_count'] ?? 0 ?></div>
                        <div class="vc-quick-stat-label">Attended</div>
                    </div>
                    
                    <?php if ($selected_opp['total_hours_worked'] ?? 0 > 0): ?>
                    <div class="vc-quick-stat">
                        <div class="vc-quick-stat-value"><?= $selected_opp['total_hours_worked'] ?></div>
                        <div class="vc-quick-stat-label">Hours</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="vc-quick-stat">
                        <div class="vc-quick-stat-value">
                            <?php if (isset($selected_opp['applications_summary']['pending'])): ?>
                                <?= $selected_opp['applications_summary']['pending'] ?>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </div>
                        <div class="vc-quick-stat-label">Pending</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="vc-details-tabs">
                    <nav class="vc-tabs-nav">
                        <button class="vc-tab-btn active" data-tab="overview">Overview</button>
                        <button class="vc-tab-btn" data-tab="applicants">Applicants</button>
                        <button class="vc-tab-btn" data-tab="requirements">Requirements</button>
                        <button class="vc-tab-btn" data-tab="media">Media</button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="vc-tab-content">
                    <!-- Overview Tab -->
                    <div class="vc-tab-pane active" id="overview">
                        <div class="vc-overview-grid">
                            <!-- Main Content -->
                            <div class="vc-overview-main">
                                <?php if ($selected_opp['description']): ?>
                                <div class="vc-section">
                                    <h3><i class="fas fa-align-left"></i> Description</h3>
                                    <div class="vc-section-content">
                                        <?= nl2br(htmlspecialchars($selected_opp['description'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($selected_opp['responsibilities']): ?>
                                <div class="vc-section">
                                    <h3><i class="fas fa-tasks"></i> Responsibilities</h3>
                                    <div class="vc-section-content">
                                        <?= nl2br($selected_opp['responsibilities']) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($selected_opp['benefits']): ?>
                                <div class="vc-section">
                                    <h3><i class="fas fa-gift"></i> Benefits</h3>
                                    <div class="vc-section-content">
                                        <?= nl2br(htmlspecialchars($selected_opp['benefits'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Sidebar -->
                            <div class="vc-overview-sidebar">
                                <!-- Details Card -->
                                <div class="vc-details-card">
                                    <h3><i class="fas fa-info-circle"></i> Details</h3>
                                    <div class="vc-details-list">
                                        <?php if ($selected_opp['city']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Location:</span>
                                            <span class="vc-detail-value">
                                                <?= htmlspecialchars($selected_opp['city']) ?>
                                                <?php if ($selected_opp['state']): ?>, <?= htmlspecialchars($selected_opp['state']) ?><?php endif; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($selected_opp['start_date']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Date:</span>
                                            <span class="vc-detail-value">
                                                <?= date('M d, Y', strtotime($selected_opp['start_date'])) ?>
                                                <?php if ($selected_opp['end_date'] && $selected_opp['end_date'] != $selected_opp['start_date']): ?>
                                                    - <?= date('M d, Y', strtotime($selected_opp['end_date'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($selected_opp['start_time']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Time:</span>
                                            <span class="vc-detail-value">
                                                <?= date('g:i A', strtotime($selected_opp['start_time'])) ?>
                                                <?php if ($selected_opp['end_time']): ?>
                                                    - <?= date('g:i A', strtotime($selected_opp['end_time'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($selected_opp['min_age']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Minimum Age:</span>
                                            <span class="vc-detail-value"><?= $selected_opp['min_age'] ?> years</span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($selected_opp['application_deadline']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Deadline:</span>
                                            <span class="vc-detail-value">
                                                <?= date('M d, Y', strtotime($selected_opp['application_deadline'])) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($selected_opp['number_of_volunteers']): ?>
                                        <div class="vc-detail-item">
                                            <span class="vc-detail-label">Volunteers Needed:</span>
                                            <span class="vc-detail-value"><?= $selected_opp['number_of_volunteers'] ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Skills Card -->
                                <?php if (!empty($selected_opp['skills'])): ?>
                                <div class="vc-details-card">
                                    <h3><i class="fas fa-tools"></i> Required Skills</h3>
                                    <div class="vc-skills-list">
                                        <?php 
                                        // Group skills by category
                                        $skills_by_category = [];
                                        foreach ($selected_opp['skills'] as $skill) {
                                            $category = $skill['category_name'] ?? 'Other';
                                            if (!isset($skills_by_category[$category])) {
                                                $skills_by_category[$category] = [];
                                            }
                                            $skills_by_category[$category][] = $skill['skill_name'];
                                        }
                                        ?>
                                        
                                        <?php foreach ($skills_by_category as $category => $skills): ?>
                                            <?php if ($category): ?>
                                            <div class="vc-category-group">
                                                <div class="vc-category-title"><?= htmlspecialchars($category) ?></div>
                                                <div class="vc-category-tags">
                                                    <?php foreach ($skills as $skill): ?>
                                                    <span class="vc-tag vc-tag-skill">
                                                        <?= htmlspecialchars($skill) ?>
                                                    </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                                <?php foreach ($skills as $skill): ?>
                                                <span class="vc-tag vc-tag-skill">
                                                    <?= htmlspecialchars($skill) ?>
                                                </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Interests Card -->
                                <?php if (!empty($selected_opp['interests'])): ?>
                                <div class="vc-details-card">
                                    <h3><i class="fas fa-heart"></i> Related Interests</h3>
                                    <div class="vc-interests-list">
                                        <?php 
                                        // Group interests by category
                                        $interests_by_category = [];
                                        foreach ($selected_opp['interests'] as $interest) {
                                            $category = $interest['category_name'] ?? 'Other';
                                            if (!isset($interests_by_category[$category])) {
                                                $interests_by_category[$category] = [];
                                            }
                                            $interests_by_category[$category][] = $interest['interest_name'];
                                        }
                                        ?>
                                        
                                        <?php foreach ($interests_by_category as $category => $interests): ?>
                                            <?php if ($category): ?>
                                            <div class="vc-category-group">
                                                <div class="vc-category-title"><?= htmlspecialchars($category) ?></div>
                                                <div class="vc-category-tags">
                                                    <?php foreach ($interests as $interest): ?>
                                                    <span class="vc-tag vc-tag-interest">
                                                        <?= htmlspecialchars($interest) ?>
                                                    </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                                <?php foreach ($interests as $interest): ?>
                                                <span class="vc-tag vc-tag-interest">
                                                    <?= htmlspecialchars($interest) ?>
                                                </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Applicants Tab -->
                    <div class="vc-tab-pane" id="applicants">
                        <div class="vc-applicants-section">
                            <div class="vc-applicants-header">
                                <h3>Applications Summary</h3>
                                <a href="applicants_manager.php?id=<?= $selected_opp['opportunity_id'] ?>" 
                                   class="vc-btn vc-btn-primary">
                                    <i class="fas fa-clipboard-list"></i> Manage Applications
                                </a>
                            </div>
                            
                            <?php if (isset($selected_opp['applications_summary'])): ?>
                            <div class="vc-applicants-stats">
                                <?php foreach ($selected_opp['applications_summary'] as $status => $count): ?>
                                <div class="vc-app-stat vc-app-stat-<?= $status ?>">
                                    <div class="vc-app-stat-value"><?= $count ?></div>
                                    <div class="vc-app-stat-label">
                                        <?= ucfirst($status) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($selected_opp['participation_summary'])): ?>
                            <div class="vc-participation-summary">
                                <h4>Participation Summary</h4>
                                <div class="vc-participation-stats">
                                    <?php foreach ($selected_opp['participation_summary'] as $status => $count): ?>
                                    <div class="vc-part-stat vc-part-stat-<?= $status ?>">
                                        <div class="vc-part-stat-value"><?= $count ?></div>
                                        <div class="vc-part-stat-label">
                                            <?= ucfirst($status) ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($selected_opp['total_hours_worked']) && $selected_opp['total_hours_worked'] > 0): ?>
                            <div class="vc-hours-summary">
                                <div class="vc-hours-card">
                                    <i class="fas fa-clock"></i>
                                    <div class="vc-hours-info">
                                        <div class="vc-hours-value"><?= $selected_opp['total_hours_worked'] ?></div>
                                        <div class="vc-hours-label">Total Hours Worked</div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Requirements Tab -->
                    <div class="vc-tab-pane" id="requirements">
                        <div class="vc-requirements-section">
                            <?php if ($selected_opp['requirements']): ?>
                            <div class="vc-section">
                                <h3><i class="fas fa-check-circle"></i> Requirements</h3>
                                <div class="vc-section-content">
                                    <?= nl2br(htmlspecialchars($selected_opp['requirements'])) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Contacts -->
                            <?php if (!empty($selected_opp['contacts'])): ?>
                            <div class="vc-section">
                                <h3><i class="fas fa-address-book"></i> Contact Persons</h3>
                                <div class="vc-contacts-grid">
                                    <?php foreach ($selected_opp['contacts'] as $contact): ?>
                                    <div class="vc-contact-card <?= $contact['is_primary'] ? 'primary' : '' ?>">
                                        <div class="vc-contact-header">
                                            <h4>
                                                <?= htmlspecialchars($contact['contact_name']) ?>
                                                <?php if ($contact['is_primary']): ?>
                                                <span class="vc-primary-badge">Primary</span>
                                                <?php endif; ?>
                                            </h4>
                                        </div>
                                        <div class="vc-contact-body">
                                            <?php if ($contact['contact_email']): ?>
                                            <div class="vc-contact-item">
                                                <i class="fas fa-envelope"></i>
                                                <a href="mailto:<?= htmlspecialchars($contact['contact_email']) ?>">
                                                    <?= htmlspecialchars($contact['contact_email']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($contact['contact_phone']): ?>
                                            <div class="vc-contact-item">
                                                <i class="fas fa-phone"></i>
                                                <a href="tel:<?= htmlspecialchars($contact['contact_phone']) ?>">
                                                    <?= htmlspecialchars($contact['contact_phone']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Media Tab -->
                    <div class="vc-tab-pane" id="media">
                        <div class="vc-media-section">
                            <?php if (empty($selected_opp['images'])): ?>
                            <div class="vc-empty-media">
                                <i class="fas fa-images"></i>
                                <p>No images uploaded for this opportunity.</p>
                                <a href="edit_opportunity.php?id=<?= $selected_opp['opportunity_id'] ?>" 
                                   class="vc-btn vc-btn-primary">
                                    <i class="fas fa-upload"></i> Upload Images
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="vc-media-grid">
                                <?php foreach ($selected_opp['images'] as $image): ?>
                                <div class="vc-media-item">
                                    <img src="<?= htmlspecialchars($image['image_url']) ?>" 
                                        alt="Opportunity Image">
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab functionality
    const tabButtons = document.querySelectorAll('.vc-tab-btn');
    const tabPanes = document.querySelectorAll('.vc-tab-pane');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            
            // Update active tab button
            tabButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Show corresponding tab pane
            tabPanes.forEach(pane => {
                pane.classList.remove('active');
                if (pane.id === tabName) {
                    pane.classList.add('active');
                }
            });
        });
    });
    
    // Auto-hide flash messages after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.vc-alert').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + F to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.querySelector('.vc-search-wrapper input');
            if (searchInput) searchInput.focus();
        }
    });
    
    // Confirm dangerous actions
    document.querySelectorAll('a[onclick*="confirm"]').forEach(link => {
        link.addEventListener('click', function(e) {
            const message = this.getAttribute('onclick').match(/return confirm\('([^']+)'/)?.[1];
            if (message && !confirm(message)) {
                e.preventDefault();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/views/layout/footer.php'; ?>