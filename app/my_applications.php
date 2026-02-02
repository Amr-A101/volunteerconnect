<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

require_role('vol');
$user = current_user();
$volunteer_id = $user['user_id'];

global $dbc;

/* =========================
   GET SUMMARY STATISTICS
========================= */
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END), 0) AS shortlisted,
        COALESCE(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected,
        COALESCE(SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END), 0) AS withdrawn
    FROM applications 
    WHERE volunteer_id = ?
";

$stmt = $dbc->prepare($stats_sql);
$stmt->bind_param("i", $volunteer_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* =========================
   GET SEARCH & FILTER PARAMS
========================= */
$active_tab = $_GET['tab'] ?? 'all';
$search = $_GET['search'] ?? '';
$date_filter = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = intval($_GET['per_page'] ?? 10);
$offset = ($page - 1) * $per_page;

/* =========================
   BUILD WHERE CONDITIONS
========================= */
$where_conditions = ["a.volunteer_id = ?", "o.status != 'deleted'"];
$params = [$volunteer_id];
$param_types = "i";

// Status filter
if ($active_tab !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $active_tab;
    $param_types .= "s";
}

// Search filter
if (!empty($search)) {
    $where_conditions[] = "(o.title LIKE ? OR org.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "ss";
}

// Date filter
if (!empty($date_filter)) {
    $date_conditions = [
        '7days' => "a.applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30days' => "a.applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'older' => "a.applied_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    ];
    
    if (isset($date_conditions[$date_filter])) {
        $where_conditions[] = $date_conditions[$date_filter];
    }
}

// Build WHERE clause
$where_clause = empty($where_conditions) ? "1=1" : implode(" AND ", $where_conditions);

/* =========================
   GET TOTAL COUNT FOR PAGINATION
========================= */
$count_sql = "
    SELECT COUNT(*) as total
    FROM applications a
    JOIN opportunities o ON o.opportunity_id = a.opportunity_id
    JOIN organizations org ON org.org_id = o.org_id
    WHERE $where_clause
";

$stmt = $dbc->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$count_result = $stmt->get_result()->fetch_assoc();
$total_apps = $count_result['total'];
$total_pages = ceil($total_apps / $per_page);
$stmt->close();

/* =========================
   GET APPLICATIONS WITH PAGINATION
========================= */
$sql = "
    SELECT 
        a.application_id,
        a.status AS application_status,
        a.applied_at,
        a.response_at,
        o.opportunity_id,
        o.title,
        o.description,
        o.city,
        o.state,
        o.start_date,
        o.end_date,
        o.application_deadline,
        o.status AS opportunity_status,
        o.number_of_volunteers,
        org.org_id,
        org.name as org_name,
        org.profile_picture as org_logo,
        (
            SELECT COUNT(*) 
            FROM applications a2 
            WHERE a2.opportunity_id = o.opportunity_id
        ) as total_applicants
    FROM applications a
    JOIN opportunities o ON o.opportunity_id = a.opportunity_id
    JOIN organizations org ON org.org_id = o.org_id
    WHERE $where_clause
    ORDER BY a.applied_at DESC
    LIMIT ? OFFSET ?
";

// Add pagination parameters
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = $dbc->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$apps = $stmt->get_result();
$stmt->close();

/* =========================
   REAPPLY-ELIGIBLE COUNT
========================= */
$reapply_sql = "
    SELECT COUNT(*) AS can_reapply
    FROM applications a
    JOIN opportunities o ON o.opportunity_id = a.opportunity_id
    WHERE a.volunteer_id = ?
        AND a.status IN ('withdrawn','rejected')
        AND o.status = 'open'
        AND o.status NOT IN ('canceled','deleted','suspended')
        AND (
            o.application_deadline IS NULL
            OR o.application_deadline >= NOW()
        )
        -- OPTIONAL cooldown logic
        AND (
                a.response_at IS NULL
                OR a.response_at <= DATE_SUB(NOW(), INTERVAL 6 HOUR)
            )
";

$stmt = $dbc->prepare($reapply_sql);
$stmt->bind_param("i", $volunteer_id);
$stmt->execute();
$reapply_count = (int)$stmt->get_result()->fetch_assoc()['can_reapply'];
$stmt->close();


$page_title = "My Applications";
require_once __DIR__ . '/views/layout/header.php';

?>

<!-- Font Awesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="/volcon/assets/js/utils/scroll-to-top.js"></script>


<div class="vc-applications-header">
    <a href="dashboard_vol.php" class="vc-btn vc-btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    <div>
        <h1>My Applications</h1>
        <p class="vc-subtitle">
            Viewing list of applied opportunities
        </p>
    </div>
</div>

<div class="vc-applications-page">

    <!-- LEFT FILTERS SIDEBAR -->
    <aside class="vc-filters-sidebar">
        <h3 class="vc-filters-title">
            <i class="fas fa-filter"></i> Filters
        </h3>

        <!-- Search Box -->
        <div class="vc-search-group">
            <form method="GET" action="">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date_filter) ?>">
                <input type="hidden" name="page" value="1">
                
                <input type="text" 
                       name="search" 
                       class="vc-search-input" 
                       placeholder="Search opportunities or organizations..."
                       value="<?= htmlspecialchars($search) ?>"
                       autocomplete="off">
                
                <button type="submit" style="display: none;"></button>
            </form>
        </div>

        <!-- Date Filters -->
        <div class="vc-date-filters">
            <h4 class="vc-filter-subtitle">
                <i class="fas fa-calendar-alt"></i> Date Applied
            </h4>
            <div class="vc-date-options">
                <?php
                $date_options = [
                    '' => 'All Time',
                    '7days' => 'Last 7 Days',
                    '30days' => 'Last 30 Days',
                    'older' => 'Older'
                ];
                ?>
                <?php foreach ($date_options as $value => $label): ?>
                    <a href="?tab=<?= $active_tab ?>&search=<?= urlencode($search) ?>&date=<?= $value ?>&page=1"
                       class="vc-date-option <?= $date_filter === $value ? 'active' : '' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Status Filters -->
        <div class="vc-status-filters">
            <h4 class="vc-filter-subtitle">
                <i class="fas fa-tags"></i> Quick Status
            </h4>
            <div class="vc-status-badges">
                <?php
                $status_colors = [
                    'pending' => 'warning',
                    'shortlisted' => 'info',
                    'accepted' => 'success',
                    'rejected' => 'danger',
                    'withdrawn' => 'secondary'
                ];
                ?>
                <?php foreach ($status_colors as $status => $color): ?>
                    <a href="?tab=<?= $status ?>&search=<?= urlencode($search) ?>&date=<?= $date_filter ?>"
                       class="vc-status-filter-badge vc-badge <?= $status ?> <?= $active_tab === $status ? 'active' : '' ?>">
                        <i class="fas fa-circle"></i> <?= ucfirst($status) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Reset Filters -->
        <div class="vc-reset-filters">
            <a href="my_applications.php" class="vc-btn-reset">
                <i class="fas fa-redo"></i> Reset All Filters
            </a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="vc-applications-main">

        <!-- Statistics Cards -->
        <div class="vc-app-stats">
            <div class="vc-stat-card">
                <span class="vc-stat-number"><?= $stats['total'] ?></span>
                <span class="vc-stat-label">Total Applications</span>
            </div>
            <div class="vc-stat-card">
                <span class="vc-stat-number"><?= $stats['pending'] ?></span>
                <span class="vc-stat-label">Pending</span>
            </div>
            <div class="vc-stat-card">
                <span class="vc-stat-number"><?= $stats['accepted'] ?></span>
                <span class="vc-stat-label">Accepted</span>
            </div>
            <div class="vc-stat-card">
                <span class="vc-stat-number"><?= $stats['rejected'] ?></span>
                <span class="vc-stat-label">Rejected</span>
            </div>

            <?php if ($reapply_count > 0): ?>
                <div class="vc-stat-card">
                    <span class="vc-stat-number"><?= $reapply_count ?></span>
                    <span class="vc-stat-label">Can Be Reapplied</span>
                </div>
            <?php endif; ?>
        </div>
        

        <!-- Tabs with Counts -->
        <div class="vc-tabs-header">
            <div class="vc-tabs-container">
                <?php
                $tabs = [
                    'all' => 'All Applications',
                    'pending' => 'Pending',
                    'shortlisted' => 'Shortlisted',
                    'accepted' => 'Accepted',
                    'rejected' => 'Rejected',
                    'withdrawn' => 'Withdrawn'
                ];
                
                $tab_counts = [
                    'all' => $stats['total'],
                    'pending' => $stats['pending'],
                    'shortlisted' => $stats['shortlisted'],
                    'accepted' => $stats['accepted'],
                    'rejected' => $stats['rejected'],
                    'withdrawn' => $stats['withdrawn']
                ];
                ?>
                
                <?php foreach ($tabs as $tab => $label): ?>
                    <a href="?tab=<?= $tab ?>&search=<?= urlencode($search) ?>&date=<?= $date_filter ?>"
                       class="vc-tab <?= $active_tab === $tab ? 'active' : '' ?>">
                        <?= $label ?>
                        <span class="vc-tab-count"><?= $tab_counts[$tab] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Applications Container -->
        <div class="vc-applications-container">
            <div class="vc-apps-header">
                <h2 class="vc-apps-title">List of Applications</h2>
                <span class="vc-results-count">
                    Showing <?= min($per_page, $apps->num_rows) ?> of <?= $total_apps ?> results
                </span>
            </div>

            <!-- Applications Grid -->
            <div class="vc-applications-grid">
                <?php if ($apps->num_rows === 0): ?>
                    <div class="vc-empty-state">
                        <div class="vc-empty-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>No applications found</h3>
                        <p>
                            <?php if (!empty($search) || !empty($date_filter) || $active_tab !== 'all'): ?>
                                Try adjusting your filters to see more results.
                            <?php else: ?>
                                You haven't applied for any opportunities yet.
                            <?php endif; ?>
                        </p>
                        <a href="browse_opportunities.php" class="vc-btn vc-btn-primary">
                            <i class="fas fa-search"></i> Browse Opportunities
                        </a>
                    </div>
                <?php else: ?>
                    <?php while ($row = $apps->fetch_assoc()): 
                        // Determine timeline status
                        $timeline_steps = [];
                        $current_step = 1;
                        
                        $timeline_steps[] = [
                            'title' => 'Application Submitted',
                            'date' => $row['applied_at'],
                            'completed' => true,
                            'active' => $row['application_status'] == 'pending'
                        ];
                        
                        if (in_array($row['application_status'], ['shortlisted', 'accepted', 'rejected'])) {
                            $timeline_steps[] = [
                                'title' => 'Under Review',
                                'date' => $row['applied_at'],
                                'completed' => true,
                                'active' => false
                            ];
                        }
                        
                        if ($row['application_status'] == 'shortlisted') {
                            $timeline_steps[] = [
                                'title' => 'Shortlisted',
                                'date' => $row['response_at'] ?? $row['applied_at'],
                                'completed' => true,
                                'active' => true
                            ];
                            $current_step = 3;
                        } elseif ($row['application_status'] == 'accepted') {
                            $timeline_steps[] = [
                                'title' => 'Accepted',
                                'date' => $row['response_at'] ?? $row['applied_at'],
                                'completed' => true,
                                'active' => false
                            ];
                            $current_step = 4;
                        } elseif ($row['application_status'] == 'rejected') {
                            $timeline_steps[] = [
                                'title' => 'Application Rejected',
                                'date' => $row['response_at'] ?? $row['applied_at'],
                                'completed' => true,
                                'active' => false,
                                'rejected' => true
                            ];
                            $current_step = 4;
                        }
                        
                        // Status icons
                        $status_icons = [
                            'pending' => '⏳',
                            'shortlisted' => '⭐',
                            'accepted' => '✅',
                            'rejected' => '❌',
                            'withdrawn' => '↩'
                        ];
                        
                        // Check if withdraw is allowed
                        $canWithdraw = in_array($row['application_status'], ['pending', 'shortlisted']) &&
                                      !in_array($row['opportunity_status'], ['ongoing', 'completed', 'cancelled', 'closed']);
                        
                        //Check if reapply is allowed
                            $canReapply = in_array($row['application_status'], ['withdrawn', 'rejected']) &&
                                        in_array($row['opportunity_status'], ['open']) &&
                                        (empty($row['application_deadline']) || strtotime($row['application_deadline']) > time());
                        ?>
                        <div class="vc-app-card" data-status="<?= $row['application_status'] ?>">
                            <div class="vc-app-header">
                                <div class="vc-app-main-info">
                                    <h3 class="vc-app-title">
                                        <?= htmlspecialchars($row['title']) ?>
                                        <?php if ($row['application_deadline'] && strtotime($row['application_deadline']) < time()): ?>
                                            <span class="vc-badge closed" title="Deadline passed">
                                                <i class="fas fa-clock"></i> Closed
                                            </span>
                                        <?php endif; ?>
                                    </h3>
                                    
                                    <p class="vc-app-org">
                                        <i class="fas fa-building"></i>
                                        <?= htmlspecialchars($row['org_name']) ?>
                                    </p>
                                    
                                    <p class="vc-app-location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($row['city']) ?>, <?= htmlspecialchars($row['state']) ?>
                                    </p>
                                </div>
                                
                                <div class="vc-app-status-badge">
                                    <span class="vc-badge <?= $row['application_status'] ?> vc-tooltip"
                                          title="<?= get_status_tooltip($row['application_status']) ?>">
                                        <?= $status_icons[$row['application_status']] ?? '' ?>
                                        <?= ucfirst($row['application_status']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="vc-app-status-section">
                                <div class="vc-status-item">
                                    <span class="vc-status-label">Application Status</span>
                                    <span class="vc-badge <?= $row['application_status'] ?>">
                                        <i class="fas fa-user-check"></i>
                                        <?= ucfirst($row['application_status']) ?>
                                    </span>
                                </div>
                                
                                <div class="vc-status-item">
                                    <span class="vc-status-label">Opportunity Status</span>
                                    <span class="vc-badge <?= $row['opportunity_status'] ?>">
                                        <i class="fas fa-bullhorn"></i>
                                        <?= ucfirst($row['opportunity_status']) ?>
                                    </span>
                                </div>
                                
                                <div class="vc-status-item">
                                    <span class="vc-status-label">Total Applicants</span>
                                    <span class="vc-applicant-count">
                                        <i class="fas fa-users"></i>
                                        <?= $row['total_applicants'] ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Application Timeline -->
                            <div class="vc-app-timeline">
                                <h4 class="vc-timeline-title">
                                    <i class="fas fa-stream"></i> Application Timeline
                                    
                                    <button type="button" class="vc-timeline-toggle" aria-expanded="true">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                </h4>
                                <div class="vc-timeline-steps collapsed">
                                    <?php foreach ($timeline_steps as $index => $step): ?>
                                        <div class="vc-timeline-step 
                                            <?= $step['completed'] ? 'completed' : '' ?> 
                                            <?= $step['active'] ? 'active' : '' ?>
                                            <?= !empty($step['rejected']) ? 'rejected' : '' ?>
                                        ">
                                            <div class="vc-timeline-dot"></div>
                                            <div class="vc-timeline-info">
                                                <strong><?= $step['title'] ?></strong>
                                                <div class="vc-timeline-date">
                                                    <?= date('M d, Y', strtotime($step['date'])) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if ($row['application_status'] == 'pending'): ?>
                                        <div class="vc-timeline-step active">
                                            <div class="vc-timeline-dot"></div>
                                            <div class="vc-timeline-info">
                                                <strong>Waiting for Review</strong>
                                                <div class="vc-timeline-date">
                                                    Organization will review your application
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="vc-app-footer">
                                <div class="vc-app-date">
                                    <i class="far fa-calendar"></i>
                                    Applied on <?= date('F d, Y', strtotime($row['applied_at'])) ?>
                                    
                                    <?php if ($row['application_deadline']): ?>
                                        <span style="margin-left: 16px;">
                                            <i class="far fa-clock"></i>
                                            Deadline: <?= date('M d, Y', strtotime($row['application_deadline'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="vc-app-actions">
                                    <a href="view_opportunity.php?id=<?= $row['opportunity_id'] ?>" 
                                       class="vc-btn vc-btn-primary">
                                        <i class="fas fa-eye"></i> View Opportunity
                                    </a>
                                    
                                    <?php if ($canWithdraw): ?>
                                        <a href="withdraw_application.php?id=<?= $row['application_id'] ?>"
                                           onclick="return confirmWithdraw(event)"
                                           class="vc-btn vc-btn-danger">
                                           <i class="fas fa-sign-out-alt"></i> Withdraw
                                        </a>
                                    <?php elseif ($canReapply): ?>
                                        <?php if ($row['application_status'] === 'withdrawn'): ?>
                                            <a href="apply_opportunity.php?id=<?= $row['opportunity_id'] ?>"
                                            onclick="return confirmReapply(event)"
                                            class="vc-btn vc-btn-warning">
                                            <i class="fas fa-redo"></i> Reapply
                                            </a>
                                        <?php elseif ($row['application_status'] === 'rejected'): ?>
                                            <a href="apply_opportunity.php?id=<?= $row['opportunity_id'] ?>"
                                            onclick="return confirmReapply(event, 'rejected')"
                                            class="vc-btn vc-btn-secondary">
                                            <i class="fas fa-hand-paper"></i> Reapply
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="vc-pagination-controls">
                    <div class="vc-page-info">
                        Page <?= $page ?> of <?= $total_pages ?>
                    </div>
                    
                    <div class="vc-pagination-buttons">
                        <!-- Previous Button -->
                        <a href="?tab=<?= $active_tab ?>&search=<?= urlencode($search) ?>&date=<?= $date_filter ?>&page=<?= max(1, $page - 1) ?>&per_page=<?= $per_page ?>"
                           class="vc-page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                           <?= $page <= 1 ? 'onclick="return false;"' : '' ?>>
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<a href="?tab=' . $active_tab . '&search=' . urlencode($search) . '&date=' . $date_filter . '&page=1&per_page=' . $per_page . '" class="vc-page-btn">1</a>';
                            if ($start_page > 2) echo '<span class="vc-page-btn disabled">...</span>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?tab=<?= $active_tab ?>&search=<?= urlencode($search) ?>&date=<?= $date_filter ?>&page=<?= $i ?>&per_page=<?= $per_page ?>"
                               class="vc-page-btn <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<span class="vc-page-btn disabled">...</span>';
                            echo '<a href="?tab=' . $active_tab . '&search=' . urlencode($search) . '&date=' . $date_filter . '&page=' . $total_pages . '&per_page=' . $per_page . '" class="vc-page-btn">' . $total_pages . '</a>';
                        }
                        ?>
                        
                        <!-- Next Button -->
                        <a href="?tab=<?= $active_tab ?>&search=<?= urlencode($search) ?>&date=<?= $date_filter ?>&page=<?= min($total_pages, $page + 1) ?>&per_page=<?= $per_page ?>"
                           class="vc-page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"
                           <?= $page >= $total_pages ? 'onclick="return false;"' : '' ?>>
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <!-- Results Per Page -->
                    <div class="vc-page-size">
                        <span>Show:</span>
                        <select onchange="changePerPage(this.value)">
                            <?php
                            $options = [10, 30, 50, 100];
                            foreach ($options as $option):
                            ?>
                                <option value="<?= $option ?>" <?= $per_page == $option ? 'selected' : '' ?>>
                                    <?= $option ?> per page
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php include __DIR__ . '/views/components/volunteer_commitment_modal.php'; ?>

<!-- Helper Functions -->
<?php
function get_status_tooltip($status) {
    $tooltips = [
        'pending' => 'Awaiting organization review',
        'shortlisted' => 'You are under consideration',
        'accepted' => 'Your application has been accepted!',
        'rejected' => 'Your application was not selected. You may reapply if the opportunity is still open.',
        'withdrawn' => 'You withdrew your application. You may reapply if the opportunity is still open.'
    ];
    return $tooltips[$status] ?? '';
}
?>

<!-- JavaScript -->
<script>
    function changePerPage(value) {
        const url = new URL(window.location);
        url.searchParams.set('per_page', value);
        url.searchParams.set('page', 1); // Reset to first page
        window.location = url;
    }

    function confirmReapply(event, status = 'withdrawn') {
        event.preventDefault();
        const url = event.target.closest('a').href;
        
        let message = 'Are you sure you want to reapply for this opportunity?';
        
        if (status === 'rejected') {
            message = 'Your previous application was rejected. Are you sure you want to reapply?';
        }
        
        message += '\n\nNote: You may need to meet current eligibility requirements.';
        
        if (confirm(message)) {
            window.location.href = url;
        }
        return false;
    }

    function confirmWithdraw(event) {
        event.preventDefault();
        const url = event.target.closest('a').href;
        
        if (confirm('Are you sure you want to withdraw your application?\n\nThis action cannot be undone.')) {
            window.location.href = url;
        }
        return false;
    }

    // Auto-submit search on enter with delay
    let searchTimer;
    document.querySelector('.vc-search-input').addEventListener('input', function(e) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            this.closest('form').submit();
        }, 500);
    });

    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + F to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            document.querySelector('.vc-search-input').focus();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            const searchInput = document.querySelector('.vc-search-input');
            if (searchInput.value) {
                searchInput.value = '';
                searchInput.closest('form').submit();
            }
        }
    });

    document.querySelectorAll('.vc-app-timeline').forEach(function(timeline) {
        const toggleBtn = timeline.querySelector('.vc-timeline-toggle');
        const steps = timeline.querySelector('.vc-timeline-steps');

        toggleBtn.addEventListener('click', function() {
            const isCollapsed = steps.classList.toggle('collapsed');
            toggleBtn.setAttribute('aria-expanded', !isCollapsed);

            // Change icon
            const icon = toggleBtn.querySelector('i');
            if (isCollapsed) {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            } else {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
        });
    });

</script>

<link rel="stylesheet" href="/volcon/assets/css/my_applications.css">

<?php require_once __DIR__ . '/views/layout/footer.php'; ?>