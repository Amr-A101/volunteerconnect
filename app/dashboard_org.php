<?php
// dashboard_org.php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';

require_role('org');

$user = current_user();
$org_id = (int)$user['user_id'];

if ($user['role'] !== 'org') {
    die("Forbidden");
}

$stmt = $dbc->prepare("SELECT status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$status = $stmt->get_result()->fetch_assoc()['status'];

if ($status !== 'verified') {
    header("Location: verify_org.php");
    exit;
}

$page_title = "My Dashboard";
require_once __DIR__ . '/views/layout/header.php';

/* =========================
   AUTO STATUS TRANSITIONS
========================= */
require_once __DIR__ . '/core/auto_opp_trans.php';
runOpportunityAutoTransitions($dbc, $org_id);


/* =========================
   FETCH ORGANIZATION PROFILE
========================= */
$stmt = $dbc->prepare("
    SELECT 
        o.*,
        u.email,
        u.username
    FROM organizations o
    JOIN users u ON u.user_id = o.org_id
    WHERE o.org_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$organization = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* =========================
   RATING SUMMARY
========================= */
$stmt = $dbc->prepare("
    SELECT 
        COUNT(*) AS total_reviews, 
        AVG(rating) AS avg_rating
    FROM reviews 
    WHERE reviewee_type = 'organization' 
      AND reviewee_id = ?
");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$rating = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_reviews = (int)$rating['total_reviews'];
$avg_rating = $rating['avg_rating'] ? round($rating['avg_rating'], 1) : 0;

/* =========================
   MY OPPORTUNITIES
========================= */
$active_tab = $_GET['tab'] ?? 'open';
$status_condition = '';
$param_types = "i";
$params = [$org_id];

if ($active_tab === 'open') {
    $status_condition = "AND o.status = 'open'";
} elseif ($active_tab === 'closed') {
    $status_condition = "AND o.status = 'closed'";
} elseif ($active_tab === 'ongoing') {
    $status_condition = "AND o.status = 'ongoing'";
} elseif ($active_tab === 'draft') {
    $status_condition = "AND o.status = 'draft'";
} elseif ($active_tab === 'completed') {
    $status_condition = "AND o.status = 'completed'";
}
// 'all' tab shows all statuses except 'deleted'

$sql = "
    SELECT 
        o.*,
        COUNT(DISTINCT a.application_id) AS applied_count,
        SUM(a.status = 'accepted') AS accepted_count,
        DATE(o.created_at) as created_date,
        DATE(o.end_date) as deadline_date
    FROM opportunities o
    LEFT JOIN applications a ON a.opportunity_id = o.opportunity_id
    WHERE o.org_id = ? 
      AND o.status != 'deleted'
      $status_condition
    GROUP BY o.opportunity_id
    ORDER BY 
        CASE o.status
            WHEN 'open' THEN 1
            WHEN 'draft' THEN 2
            WHEN 'ongoing' THEN 3
            WHEN 'completed' THEN 4
            WHEN 'closed' THEN 5
            ELSE 6
        END,
        o.created_at DESC
";

$stmt = $dbc->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$my_opps = $stmt->get_result();
$stmt->close();

/* =========================
   GET DASHBOARD STATS
========================= */
$stmt = $dbc->prepare("
    SELECT 
        /* Total opportunities (excluding deleted) */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status != 'deleted') AS total_opps,
        
        /* Open opportunities */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status = 'open') AS open_opps,
        
        /* Closed opportunities */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status = 'closed') AS closed_opps,

        /* Ongoing opportunities */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status = 'ongoing') AS ongoing_opps,

        /* Draft opportunities */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status = 'draft') AS draft_opps,
        
        /* Completed opportunities */
        (SELECT COUNT(*) FROM opportunities WHERE org_id = ? AND status = 'completed') AS completed_opps,
        
        /* Unique volunteers who applied to your opportunities */
        (SELECT COUNT(DISTINCT a.volunteer_id) 
         FROM applications a 
         JOIN opportunities o ON o.opportunity_id = a.opportunity_id 
         WHERE o.org_id = ?) AS unique_volunteers,
        
        /* Total applications to your opportunities */
        (SELECT COUNT(*) 
         FROM applications a 
         JOIN opportunities o ON o.opportunity_id = a.opportunity_id 
         WHERE o.org_id = ?) AS total_apps,
        
        /* Pending applications */
        (SELECT COUNT(*) 
         FROM applications a 
         JOIN opportunities o ON o.opportunity_id = a.opportunity_id 
         WHERE o.org_id = ? AND a.status = 'pending') AS pending_apps,
        
        /* Accepted applications */
        (SELECT COUNT(*) 
         FROM applications a 
         JOIN opportunities o ON o.opportunity_id = a.opportunity_id 
         WHERE o.org_id = ? AND a.status = 'accepted') AS accepted_apps
");

$stmt->bind_param("iiiiiiiiii", 
    $org_id, $org_id, $org_id, $org_id, $org_id, 
    $org_id, $org_id, $org_id, $org_id, $org_id
);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_opps = (int)$stats['total_opps'];
$open_opps = (int)$stats['open_opps'];
$closed_opps = (int)$stats['closed_opps'];
$ongoing_opps = (int)$stats['ongoing_opps'];
$draft_opps = (int)$stats['draft_opps'];
$completed_opps = (int)$stats['completed_opps'];
$unique_volunteers = (int)$stats['unique_volunteers'];
$total_apps = (int)$stats['total_apps'];
$pending_apps = (int)$stats['pending_apps'];
$accepted_apps = (int)$stats['accepted_apps'];
?>

<div class="vc-dashboard vc-dashboard-org">

    <!-- LEFT SIDEBAR -->
    <aside class="vc-dashboard-sidebar">

        <!-- PROFILE SECTION -->
        <div class="vc-profile-section">
            <img src="<?= htmlspecialchars($organization['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png') ?>"
                 class="vc-profile-pic" alt="Organization Logo">
            
            <div class="vc-profile-info">
                <h2><?= htmlspecialchars($organization['name']) ?></h2>
                <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                <p><i class="fas fa-map-marker-alt"></i> 
                    <?= htmlspecialchars($organization['city'] ?? '') ?>, 
                    <?= htmlspecialchars($organization['state'] ?? '') ?>
                </p>
            </div>
            
            <a href="profile_org.php" class="vc-button-edit">
                <i class="fas fa-external-link-alt"></i> View Public Profile
            </a>
        </div>

        <!-- STATS OVERVIEW -->
        <div class="vc-stats-grid">
            <div class="vc-stat-card">
                <h4>Active Opportunities</h4>
                <div class="vc-stat-value">
                    <i class="fas fa-bullhorn"></i> <?= $open_opps ?>
                </div>
            </div>
            
            <div class="vc-stat-card">
                <h4>Pending Applications</h4>
                <div class="vc-stat-value">
                    <i class="fas fa-clock"></i> <?= $pending_apps ?>
                </div>
            </div>
            
            <div class="vc-stat-card">
                <h4>Rating of You</h4>
                <div class="vc-stat-value">
                    <?= number_format($avg_rating, 1) ?: 'N/A' ?>
                    <?php if ($total_reviews > 0): ?>
                        <div class="vc-rating">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <span class="vc-rating-star">
                                    <?= $i <= floor($avg_rating) ? '★' : '☆' ?>
                                </span>
                            <?php endfor; ?>
                            <span style="margin-left: 8px; font-size: 12px; color: var(--text-secondary);">
                                (<?= $total_reviews ?>)
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="vc-quick-actions">
            <button class="vc-action-button" onclick="window.location.href='post_opportunity.php'">
                <i class="fas fa-plus-circle"></i> Post New Opportunity
            </button>
            
            <button class="vc-action-button secondary" onclick="window.location.href='applicants_manager.php?status=pending'">
                <i class="fas fa-inbox"></i> Pending Applications (<?= $pending_apps ?>)
            </button>
        </div>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="vc-dashboard-main">

        <!-- DASHBOARD HEADER -->
        <div class="vc-dashboard-header">
            <h1 class="vc-header-title">Welcome back, <?= htmlspecialchars($organization['name']) ?>!</h1>
            <p class="vc-header-subtitle">Here's what's happening with your volunteer opportunities today.</p>
            
            <div class="vc-header-stats">
                <div class="vc-header-stat">
                    <span class="vc-stat-number"><?= $total_opps ?></span>
                    <span class="vc-stat-label">Total Opportunities</span>
                </div>
                
                <div class="vc-header-stat">
                    <span class="vc-stat-number"><?= $open_opps ?></span>
                    <span class="vc-stat-label">Open</span>
                </div>
                
                <div class="vc-header-stat">
                    <span class="vc-stat-number"><?= $accepted_apps ?></span>
                    <span class="vc-stat-label">Accepted Volunteers</span>
                </div>
                
                <div class="vc-header-stat">
                    <span class="vc-stat-number"><?= $total_apps ?></span>
                    <span class="vc-stat-label">Total Applications</span>
                </div>
            </div>
        </div>

        <!-- OPPORTUNITIES SECTION -->
        <div class="vc-opportunities-section">
            <div class="vc-section-header">
                <h2 class="vc-section-title">Your Opportunities</h2>
                
                <div class="vc-tab-navigation">
                    <button class="vc-tab-btn <?= $active_tab === 'open' ? 'active' : '' ?>" 
                            onclick="switchTab('open')">
                        Open (<?= $open_opps ?>)
                    </button>
                    <button class="vc-tab-btn <?= $active_tab === 'draft' ? 'active' : '' ?>" 
                            onclick="switchTab('draft')">
                        Draft (<?= $draft_opps ?>)
                    </button>
                    <button class="vc-tab-btn <?= $active_tab === 'ongoing' ? 'active' : '' ?>" 
                            onclick="switchTab('ongoing')">
                        Ongoing (<?= $ongoing_opps ?>)
                    </button>
                    <button class="vc-tab-btn <?= $active_tab === 'completed' ? 'active' : '' ?>" 
                            onclick="switchTab('completed')">
                        Completed (<?= $completed_opps ?>)
                    </button>
                    <button class="vc-tab-btn <?= $active_tab === 'closed' ? 'active' : '' ?>" 
                            onclick="switchTab('closed')">
                        Closed (<?= $closed_opps ?>)
                    </button>
                    <button class="vc-tab-btn <?= $active_tab === 'all' ? 'active' : '' ?>" 
                            onclick="switchTab('all')">
                        All (<?= $total_opps ?>)
                    </button>
                </div>
            </div>

            <div class="vc-opportunities-grid">
                <?php if ($my_opps->num_rows > 0): ?>
                    <?php while ($opp = $my_opps->fetch_assoc()): 
                        $slots_filled = (int)$opp['accepted_count'];
                        $total_slots = (int)$opp['number_of_volunteers'];
                        $fill_percentage = $total_slots > 0 ? ($slots_filled / $total_slots) * 100 : 0;
                        $status_class = 'vc-status-' . $opp['status'];
                    ?>
                        <div class="vc-opportunity-card">
                            <div class="vc-opportunity-header">
                                <div>
                                    <span class="vc-opportunity-status <?= $status_class ?>">
                                        <?php if($opp['status'] === 'suspended'): ?>
                                            <i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>
                                        <?php endif; ?>
                                        <?= ucfirst($opp['status']) ?>
                                    </span>

                                    <h3 class="vc-opportunity-title">
                                        <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>"><?= htmlspecialchars($opp['title']) ?></a>
                                    </h3>
                                </div>
                                <div class="vc-opportunity-badge">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?= date('M d, Y', strtotime($opp['created_date'])) ?>
                                </div>
                            </div>
                            
                            <div class="vc-opportunity-meta">
                                <div class="vc-meta-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span>
                                        <?= htmlspecialchars($opp['city'] ?? '') ?>, 
                                        <?= htmlspecialchars($opp['state'] ?? '') ?>
                                    </span>
                                </div>
                                <div class="vc-meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>
                                        <?php if ($opp['start_date']): ?>
                                            <?= date('M d, Y', strtotime($opp['start_date'])) ?>
                                            <?php if ($opp['end_date']): ?>
                                                - <?= date('M d, Y', strtotime($opp['end_date'])) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Flexible
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <p class="vc-opportunity-desc">
                                <?= htmlspecialchars(substr($opp['description'], 0, 150)) . '...' ?>
                            </p>
                            
                            <div class="vc-progress-section">
                                <div class="vc-progress-label">
                                    <span>Volunteer Slots</span>
                                    <span><?= $slots_filled ?> / <?= $total_slots ?></span>
                                </div>
                                <div class="vc-progress-bar">
                                    <div class="vc-progress-fill" style="width: <?= $fill_percentage ?>%"></div>
                                </div>
                                <small class="vc-muted">
                                    <?= $opp['applied_count'] == 0
                                        ? 'No applications yet'
                                        : ($opp['applied_count'] == 1
                                            ? '1 volunteer applied'
                                            : (int)$opp['applied_count'] . ' volunteers applied')
                                    ?>
                                </small>
                            </div>
                            
                            <div class="vc-card-actions">
                            <?php switch ($opp['status']):

                                /* ===============================
                                            DRAFT
                                =============================== */
                                case 'draft': ?>
                                    <a href="edit_opportunity.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=publish"
                                    onclick="return confirm('Publish this opportunity?')"
                                    class="vc-btn vc-btn-secondary">
                                        <i class="fas fa-paper-plane"></i> Publish
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=delete"
                                    onclick="return confirm('Delete this draft?')"
                                    class="vc-btn vc-btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                <?php break;


                                /* ===============================
                                            OPEN
                                =============================== */
                                case 'open': ?>
                                    <a href="applicants_manager.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-primary">
                                        <i class="fas fa-users"></i> Applicants
                                    </a>

                                    <a href="edit_opportunity.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-secondary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=close"
                                    onclick="return confirm('Close this opportunity? Applications will stop.')"
                                    class="vc-btn vc-btn-warning">
                                        <i class="fas fa-lock"></i> Close
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=cancel"
                                    onclick="return confirm('Cancel this opportunity? Volunteers will be notified.')"
                                    class="vc-btn vc-btn-danger">
                                        <i class="fas fa-ban"></i> Cancel
                                    </a>
                                <?php break;


                                /* ===============================
                                            CLOSED
                                =============================== */
                                case 'closed': ?>
                                    <a href="applicants_manager.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-primary">
                                        <i class="fas fa-users"></i> Applicants
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=reopen"
                                    onclick="return confirm('Reopen this opportunity?')"
                                    class="vc-btn vc-btn-secondary">
                                        <i class="fas fa-lock-open"></i> Reopen
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=cancel"
                                    onclick="return confirm('Cancel this opportunity? Volunteers will be notified.')"
                                    class="vc-btn vc-btn-danger">
                                        <i class="fas fa-ban"></i> Cancel
                                    </a>
                                <?php break;


                                /* ===============================
                                            ONGOING
                                =============================== */
                                case 'ongoing': ?>
                                    <a href="participation_manager.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-primary">
                                        <i class="fas fa-user-check"></i> Participants
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=complete"
                                    onclick="return confirm('Mark this opportunity as completed?')"
                                    class="vc-btn vc-btn-success">
                                        <i class="fas fa-flag-checkered"></i> Complete
                                    </a>
                                <?php break;


                                /* ===============================
                                            COMPLETED
                                =============================== */
                                case 'completed': ?>
                                    <a href="participation_manager.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-primary">
                                        <i class="fas fa-chart-bar"></i> Summary
                                    </a>
                                <?php break;


                                /* ===============================
                                            CANCELED
                                =============================== */
                                case 'canceled': ?>
                                    <a href="applicants_manager.php?id=<?= $opp['opportunity_id'] ?>" class="vc-btn vc-btn-secondary">
                                        <i class="fas fa-eye"></i> View
                                    </a>

                                    <a href="change_status_opp.php?id=<?= $opp['opportunity_id'] ?>&action=delete"
                                    onclick="return confirm('Delete this cancelled opportunity?')"
                                    class="vc-btn vc-btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                <?php break;


                                /* ===============================
                                            SUSPENDED
                                =============================== */
                                case 'suspended': ?>
                                    <span class="vc-btn vc-btn-disabled">
                                        <i class="fas fa-ban"></i> Suspended
                                    </span>
                                <?php break;
                            endswitch; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="vc-empty-state">
                        <div class="vc-empty-icon">
                            <i class="fas fa-hands-helping"></i>
                        </div>
                        <h3>No opportunities found</h3>
                        <p>
                            <?php 
                                if ($active_tab === 'open') {
                                    echo "You don't have any open opportunities. Create one to start receiving applications!";
                                } elseif ($active_tab === 'draft') {
                                    echo "You don't have any draft opportunities.";
                                } else {
                                    echo "No opportunities match the selected filter.";
                                }
                            ?>
                        </p>
                        <a href="post_opportunity.php" class="vc-btn vc-btn-primary" style="max-width: 200px; margin: 0 auto;">
                            <i class="fas fa-plus"></i> Create Opportunity
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

</div>

<!-- Include CSS -->
<link rel="stylesheet" href="/volcon/assets/css/dashboard_org_prem.css">

<!-- JavaScript -->
<script>
function switchTab(tab){
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.location = url;
}

// Add date formatting function
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

document.addEventListener('DOMContentLoaded', function() {
    // Format dates in meta items
    document.querySelectorAll('.vc-meta-item .fa-calendar').forEach(icon => {
        const span = icon.nextElementSibling;
        if (span && span.textContent.includes('-')) {
            const dates = span.textContent.split(' - ');
            if (dates.length === 2) {
                try {
                    const startDate = formatDate(dates[0].trim());
                    const endDate = formatDate(dates[1].trim());
                    span.textContent = `${startDate} - ${endDate}`;
                } catch (e) {
                    // Keep original if formatting fails
                }
            }
        }
    });
    
    // Add progress bar animation
    const progressBars = document.querySelectorAll('.vc-progress-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => {
            bar.style.width = width;
        }, 300);
    });
    
    // Add confirmation for delete actions
    document.querySelectorAll('.vc-btn-danger').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to proceed?')) {
                e.preventDefault();
                return false;
            }
            
            // Add loading state
            const originalHTML = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            this.disabled = true;
            
            // Reset after 3 seconds if navigation doesn't happen
            setTimeout(() => {
                this.innerHTML = originalHTML;
                this.disabled = false;
            }, 3000);
        });
    });
});
</script>

<?php require_once __DIR__ . '/views/layout/footer.php'; ?>