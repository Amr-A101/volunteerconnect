<?php
require_once 'core/db.php';
require_once 'core/flash.php';

$opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($opportunity_id <= 0) {
    flash('error', 'Invalid opportunity ID.');
    header("Location: browse_opportunity.php");
    exit;
}

/* ===============================
   FETCH OPPORTUNITY WITH ENHANCED DATA
=============================== */
$stmt = $dbc->prepare("
    SELECT 
        o.*,
        org.org_id,
        org.name AS org_name,
        org.profile_picture AS org_logo,
        org.description AS org_description,
        (SELECT COUNT(*) FROM applications 
         WHERE opportunity_id = o.opportunity_id AND status = 'accepted') as accepted_count
    FROM opportunities o
    JOIN organizations org ON org.org_id = o.org_id
    WHERE o.opportunity_id = ? AND o.status != 'deleted'
    LIMIT 1
");

if (!$stmt) {
    die("Database error: " . $dbc->error);
}

$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$result = $stmt->get_result();
$opp = $result->fetch_assoc();
$stmt->close();

if (!$opp) {
    flash('error', 'Opportunity not found.');
    header("Location: view_opportunity.php?id=$opportunity_id");
    exit;
}

$page_title = htmlspecialchars($opp['title'] ?? 'Opportunity');
require_once __DIR__ . "/views/layout/header.php";

// Check if user is logged in
$user = current_user();
$is_logged_in = !!$user;
$user_id = $is_logged_in ? $user['user_id'] : null;
$role = $is_logged_in ? $user['role'] : 'guest';
$is_volunteer = ($role === 'vol');
$is_organization = ($role === 'org');
$is_admin = ($role === 'admin');

$is_owner = $is_organization && ($user_id == $opp['org_id']);
$status   = $opp['status'];

/* ===============================
   FETCH RELATED DATA WITH ERROR HANDLING
=============================== */

// Contacts
$contacts = [];
$stmt = $dbc->prepare("
    SELECT * FROM opportunity_contacts
    WHERE opportunity_id = ?
    ORDER BY is_primary DESC
");
if ($stmt) {
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $contacts = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Images
$images = [];
$stmt = $dbc->prepare("
    SELECT img_id, image_url, created_at
    FROM opportunity_images
    WHERE opportunity_id = ?
    ORDER BY created_at DESC
");
if ($stmt) {
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $images = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Skills
$skills = [];
$stmt = $dbc->prepare("
    SELECT s.skill_id, s.skill_name
    FROM opportunity_skills os
    JOIN skills s ON s.skill_id = os.skill_id
    WHERE os.opportunity_id = ?
");
if ($stmt) {
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $skills = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Interests
$interests = [];
$stmt = $dbc->prepare("
    SELECT i.interest_id, i.interest_name
    FROM opportunity_interests oi
    JOIN interests i ON i.interest_id = oi.interest_id
    WHERE oi.opportunity_id = ?
");
if ($stmt) {
    $stmt->bind_param("i", $opportunity_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $interests = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Accepted Volunteers
$accepted_volunteers = [];
if ($opp['status'] == 'ongoing' || $opp['status'] == 'completed') {
    $stmt = $dbc->prepare("
        SELECT 
            v.vol_id,
            v.first_name,
            v.last_name,
            v.profile_picture,
            a.applied_at
        FROM applications a
        JOIN volunteers v ON v.vol_id = a.volunteer_id
        WHERE a.opportunity_id = ? 
          AND a.status = 'accepted'
        ORDER BY a.applied_at ASC
        LIMIT 20
    ");
    if ($stmt) {
        $stmt->bind_param("i", $opportunity_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $accepted_volunteers = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

/* ===============================
   USER + APPLICATION STATE
=============================== */
$application = null;
$app_status_text = null;
$status_icon = '';

if ($is_volunteer && $user_id) {
    $stmt = $dbc->prepare("
        SELECT * FROM applications
        WHERE volunteer_id = ? AND opportunity_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $opportunity_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $application = $result->fetch_assoc();
        $stmt->close();
        
        if ($application) {
            switch ($application['status']) {
                case 'pending':
                    $app_status_text = 'Your application is pending review.';
                    $status_icon = '⏳';
                    break;
                case 'shortlisted':
                    $app_status_text = 'You have been shortlisted!';
                    $status_icon = '⭐';
                    break;
                case 'accepted':
                    $app_status_text = 'You have been accepted! 🎉';
                    $status_icon = '✅';
                    break;
                case 'rejected':
                    $app_status_text = 'Your application was not successful.';
                    $status_icon = '❌';
                    break;
                case 'withdrawn':
                    $app_status_text = 'You have withdrawn your application.';
                    $status_icon = '↩';
                    break;
                default:
                    $app_status_text = 'Application status unknown.';
                    $status_icon = '❓';
            }
        }
    }
}

// Calculate slots percentage
$slots_filled = (int)($opp['accepted_count'] ?? 0);
$total_slots = (int)($opp['number_of_volunteers'] ?? 0);
$slots_percentage = $total_slots > 0 ? ($slots_filled / $total_slots) * 100 : 0;

// Check application deadline
$deadline_passed = false;
if ($opp['application_deadline']) {
    $deadline_passed = strtotime($opp['application_deadline']) < time();
}

/* ===============================
   CHECK IF CAN REAPPLY
=============================== */
$can_reapply = false;
if ($application && in_array($application['status'], ['withdrawn', 'rejected']) && 
    $opp['status'] === 'open' && !$deadline_passed) {
    
    // Check slot availability
    $accepted_count = (int)$opp['accepted_count'];
    $total_slots = (int)$opp['number_of_volunteers'];
    
    if ($total_slots === 0 || $accepted_count < $total_slots) {
        $can_reapply = true;
    }
}

// Determine main image
$main_image = '';
if (!empty($opp['image_url'])) {
    $main_image = $opp['image_url'];
} elseif (!empty($images) && !empty($images[0]['image_url'])) {
    $main_image = $images[0]['image_url'];
}


$opp_status_messages = [
    // Final / non-interactive states
    'completed' => [
        'icon'  => 'fas fa-flag-checkered',
        'class' => 'completed',
        'text'  => 'This opportunity has been completed.'
    ],
    'canceled' => [
        'icon'  => 'fas fa-ban',
        'class' => 'canceled',
        'text'  => 'This opportunity has been canceled.'
    ],
    'deleted' => [
        'icon'  => 'fas fa-times-circle',
        'class' => 'deleted',
        'text'  => 'This opportunity is no longer available.'
    ],

    // Temporarily unavailable
    'suspended' => [
        'icon'  => 'fas fa-pause-circle',
        'class' => 'suspended',
        'text'  => 'This opportunity is temporarily unavailable.'
    ],

    // Closed for applications
    'closed' => [
        'icon'  => 'fas fa-lock',
        'class' => 'closed',
        'text'  => 'Applications are closed.'
    ],

    // Active but not accepting applications
    'ongoing' => [
        'icon'  => 'fas fa-running',
        'class' => 'ongoing',
        'text'  => 'This opportunity is currently ongoing.'
    ],
];

$owner_actions = [

    'open' => [
        [
            'label'   => 'Close',
            'icon'    => 'fas fa-lock',
            'class'   => 'btn-secondary',
            'action'  => 'close',
            'confirm' => 'Close applications for this opportunity?'
        ],
    ],

    'closed' => [
        [
            'label'   => 'Reopen',
            'icon'    => 'fas fa-unlock',
            'class'   => 'btn-success',
            'action'  => 'reopen',
            'confirm' => 'Reopen this opportunity?'
        ],
        [
            'label'   => 'Cancel',
            'icon'    => 'fas fa-ban',
            'class'   => 'btn-danger',
            'action'  => 'cancel',
            'confirm' => 'Cancel this opportunity permanently? \n\nWarning: This action is irreversible.'
        ],
    ],

    'suspended' => [
        [
            'label'   => 'Cancel',
            'icon'    => 'fas fa-ban',
            'class'   => 'btn-danger',
            'action'  => 'cancel',
            'confirm' => 'Cancel this suspended opportunity permanently? \n\nWarning: This action is irreversible.'
        ],
    ],

    'ongoing' => [
        [
            'label'   => 'Mark Completed',
            'icon'    => 'fas fa-flag-checkered',
            'class'   => 'btn-success',
            'action'  => 'complete',
            'confirm' => 'Mark this opportunity as completed?\n\nPlease ensure the volunteer attendance are taken before proceeding.'
        ],
    ],

    'completed' => [
        // No actions, read-only
    ],

    'canceled' => [
        [
            'label'   => 'Delete',
            'icon'    => 'fas fa-trash',
            'class'   => 'btn-danger',
            'action'  => 'delete',
            'confirm' => 'Delete this canceled opportunity permanently?\n\nWarning: This action is irreversible.'
        ],
    ],

    'deleted' => [
        // No actions
    ],
];

/* ===============================
   FETCH OPPORTUNITY RATINGS
=============================== */
$opportunity_ratings = [];
$opp_avg_rating = 0;
$opp_rating_count = 0;
$opp_review_stats = [
    'total' => 0,
    'average' => 0,
    'counts' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]
];

$rating_stmt = $dbc->prepare("
    SELECT r.*, 
           CASE 
               WHEN r.reviewer_type = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
               WHEN r.reviewer_type = 'organization' THEN o.name
           END as reviewer_name,
           CASE 
               WHEN r.reviewer_type = 'volunteer' THEN 'volunteer'
               WHEN r.reviewer_type = 'organization' THEN 'organization'
           END as reviewer_type_label,
           r.review_text,
           r.rating,
           r.created_at
    FROM reviews r
    LEFT JOIN volunteers v ON r.reviewer_type = 'volunteer' AND r.reviewer_id = v.vol_id
    LEFT JOIN organizations o ON r.reviewer_type = 'organization' AND r.reviewer_id = o.org_id
    WHERE r.opportunity_id = ?
    AND r.reviewee_type = 'organization'
    AND r.reviewee_id = ?
    ORDER BY r.created_at DESC
");
$rating_stmt->bind_param("ii", $opportunity_id, $opp['org_id']);
$rating_stmt->execute();
$rating_result = $rating_stmt->get_result();

while ($rating = $rating_result->fetch_assoc()) {
    $opportunity_ratings[] = $rating;
    $opp_review_stats['counts'][$rating['rating']]++;
}
$rating_stmt->close();

$opp_rating_count = count($opportunity_ratings);
$opp_avg_rating = 0;

if ($opp_rating_count > 0) {
    $total_rating = 0;
    foreach ($opportunity_ratings as $rating) {
        $total_rating += $rating['rating'];
    }
    $opp_avg_rating = round($total_rating / $opp_rating_count, 1);
}

$opp_review_stats['total'] = $opp_rating_count;
$opp_review_stats['average'] = $opp_avg_rating;

?>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/volcon/assets/css/opportunity_page.css">

<div class="vc-opportunity-container">
    <!-- Navigation -->
    <nav class="vc-opportunity-nav">
        <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'dashboard_vol.php') ?>" class="nav-back">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <div class="nav-actions">
            <button class="btn-share" onclick="vcShareOpportunity()">
                <i class="fas fa-share-alt"></i> Share
            </button>
            <?php if ($is_admin || $is_owner): ?>
                <a href="edit_opportunity.php?id=<?= $opportunity_id ?>" class="btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Main Header -->
    <header class="vc-opportunity-header">
        <div class="header-image">
            <?php if ($main_image): ?>
                <img src="<?= htmlspecialchars($main_image) ?>" 
                     alt="<?= htmlspecialchars($opp['title'] ?? 'Opportunity') ?>"
                     class="opportunity-cover">
            <?php else: ?>
                <div class="default-cover">
                    <i class="fas fa-hands-helping"></i>
                </div>
            <?php endif; ?>
            <span class="status-badge status-<?= htmlspecialchars($opp['status']) ?>">
                <?= ucfirst(htmlspecialchars($opp['status'])) ?>
            </span>
        </div>
        
        <div class="header-content">
            <h1 class="opportunity-title"><?= htmlspecialchars($opp['title'] ?? 'Untitled Opportunity') ?></h1>
            
            <div class="organization-info">
                <div class="org-logo">
                    <?php if (!empty($opp['org_logo'])): ?>
                        <img src="<?= htmlspecialchars($opp['org_logo']) ?>" 
                             alt="<?= htmlspecialchars($opp['org_name']) ?>">
                    <?php else: ?>
                        <div class="org-logo-placeholder">
                            <?= strtoupper(substr($opp['org_name'] ?? 'O', 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="org-details">
                    <span class="org-label">Organized by</span>
                    <a href="profile_org.php?id=<?= $opp['org_id'] ?>" class="org-name">
                        <?= htmlspecialchars($opp['org_name'] ?? 'Unknown Organization') ?>
                    </a>
                    <span class="org-posted">Posted <?= date('F d, Y', strtotime($opp['created_at'])) ?> (<?= timeAgo($opp['created_at']) ?>)</span>
                </div>
            </div>
            
            <?php if (!empty($opp['brief_summary'])): ?>
                <div class="brief-summary">
                    <?= htmlspecialchars($opp['brief_summary']) ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Ratings Section -->
    <?php if ($opp_rating_count > 0): ?>
    <section class="content-section ratings-section">
        <div class="section-header">
            <h2><i class="fas fa-star"></i> Ratings & Reviews</h2>
            <button class="btn-view-all" onclick="openOpportunityRatingsModal()">
                View All Ratings <i class="fas fa-arrow-right"></i>
            </button>
        </div>
        
        <div class="ratings-summary">
            <div class="avg-rating-box">
                <div class="avg-rating-number"><?= $opp_avg_rating ?></div>
                <div class="avg-rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <?php if ($i <= floor($opp_avg_rating)): ?>
                            <i class="fas fa-star vc-star-filled"></i>
                        <?php elseif ($i - 0.5 <= $opp_avg_rating): ?>
                            <i class="fas fa-star-half-alt vc-star-filled"></i>
                        <?php else: ?>
                            <i class="far fa-star vc-star-empty"></i>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <div class="rating-count"><?= $opp_rating_count ?> review<?= $opp_rating_count != 1 ? 's' : '' ?></div>
            </div>
            
            <div class="rating-distribution">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <div class="rating-bar-row">
                        <span class="rating-label"><?= $i ?> <i class="fas fa-star"></i></span>
                        <div class="rating-bar-bg">
                            <div class="rating-bar-fill" 
                                style="width: <?= $opp_rating_count > 0 ? ($opp_review_stats['counts'][$i] / $opp_rating_count * 100) : 0 ?>%">
                            </div>
                        </div>
                        <span class="rating-count"><?= $opp_review_stats['counts'][$i] ?></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        
        <!-- Show 2-3 sample reviews -->
        <div class="sample-reviews">
            <?php $sample_reviews = array_slice($opportunity_ratings, 0, 2); ?>
            <?php foreach ($sample_reviews as $review): ?>
            <div class="review-card">
                <div class="review-header">
                    <div class="reviewer-info">
                        <div class="reviewer-name">
                            <?= htmlspecialchars($review['reviewer_name']) ?>
                            <span class="reviewer-type">
                                (<?= ucfirst($review['reviewer_type_label']) ?>)
                            </span>
                        </div>
                        <div class="review-date">
                            <?= date('M d, Y', strtotime($review['created_at'])) ?>
                        </div>
                    </div>
                    <div class="review-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php if ($i <= $review['rating']): ?>
                                <i class="fas fa-star vc-star-filled"></i>
                            <?php else: ?>
                                <i class="far fa-star vc-star-empty"></i>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <span class="rating-value"><?= $review['rating'] ?>.0</span>
                    </div>
                </div>
                
                <?php if (!empty(trim($review['review_text']))): ?>
                <div class="review-body">
                    <p><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Quick Facts Bar -->
    <section class="vc-quick-facts">
        <div class="fact-item">
            <i class="fas fa-map-marker-alt"></i>
            <div>
                <span class="fact-label">Location</span>
                <span class="fact-value">
                    <?= htmlspecialchars($opp['city'] ?? '') ?><?= !empty($opp['city']) && !empty($opp['state']) ? ', ' : '' ?><?= htmlspecialchars($opp['state'] ?? '') ?>
                </span>
                <?php if ($opp['location_name']): ?>
                    <small class="fact-detail"><?= htmlspecialchars($opp['location_name']) ?></small>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="fact-item">
            <i class="fas fa-calendar-alt"></i>
            <div>
                <span class="fact-label">Date</span>
                <span class="fact-value">
                    <?php if ($opp['start_date']): ?>
                        <?= date('M d, Y', strtotime($opp['start_date'])) ?>
                        <?php if ($opp['end_date'] && $opp['start_date'] != $opp['end_date']): ?>
                             – <?= date('M d, Y', strtotime($opp['end_date'])) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        Flexible
                    <?php endif; ?>
                </span>
            </div>
        </div>
        
        <div class="fact-item">
            <i class="fas fa-clock"></i>
            <div>
                <span class="fact-label">Time</span>
                <span class="fact-value">
                    <?php if ($opp['start_time'] && $opp['end_time']): ?>
                        <?= date('g:i A', strtotime($opp['start_time'])) ?> – <?= date('g:i A', strtotime($opp['end_time'])) ?>
                    <?php else: ?>
                        Flexible
                    <?php endif; ?>
                </span>
            </div>
        </div>
        
        <div class="fact-item">
            <i class="fas fa-users"></i>
            <div>
                <span class="fact-label">Volunteers</span>
                <span class="fact-value"><?= $slots_filled ?>/<?= $total_slots ?></span>
                <div class="slots-progress">
                    <div class="progress-bar" style="width: <?= $slots_percentage ?>%"></div>
                </div>
            </div>
        </div>
        
        <?php if ($opp['application_deadline']): ?>
            <div class="fact-item <?= $deadline_passed ? 'deadline-passed' : '' ?>">
                <i class="fas fa-hourglass-end"></i>
                <div>
                    <span class="fact-label">Apply by</span>
                    <span class="fact-value"><?= date('M d, Y', strtotime($opp['application_deadline'])) ?></span>
                    <?php if ($deadline_passed): ?>
                        <small class="fact-detail danger">Deadline passed</small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Main Action Bar -->
    <section class="vc-action-bar">
        <div class="action-status">

            <?php if ($app_status_text): ?>

                <!-- User application status -->
                <div class="user-status">
                    <span class="status-icon"><?= $status_icon ?></span>
                    <span class="status-text"><?= $app_status_text ?></span>
                </div>

            <?php elseif (isset($opp_status_messages[$opp['status']])): ?>

                <!-- Opportunity status (non-open / read-only) -->
                <div class="availability-status <?= $opp_status_messages[$opp['status']]['class'] ?>">
                    <i class="<?= $opp_status_messages[$opp['status']]['icon'] ?>"></i>
                    <span><?= $opp_status_messages[$opp['status']]['text'] ?></span>
                </div>

            <?php elseif ($opp['status'] === 'open' && !$deadline_passed): ?>

                <!-- Open & accepting applications -->
                <div class="availability-status">
                    <?php if ($total_slots > 0 && $slots_filled < $total_slots): ?>
                        <i class="fas fa-check-circle available"></i>
                        <span><?= $total_slots - $slots_filled ?> slot<?= ($total_slots - $slots_filled > 1) ? 's' : '' ?> available</span>

                    <?php elseif ($total_slots == 0): ?>
                        <i class="fas fa-check-circle available"></i>
                        <span>Applications open</span>

                    <?php else: ?>
                        <i class="fas fa-times-circle full"></i>
                        <span>All slots filled</span>
                    <?php endif; ?>
                </div>

            <?php elseif ($deadline_passed): ?>

                <!-- Deadline fallback -->
                <div class="availability-status closed">
                    <i class="fas fa-clock"></i>
                    <span>Application deadline has passed.</span>
                </div>

            <?php endif; ?>

        </div>
        
        <div class="action-buttons">
            <?php if (!$is_logged_in): ?>
                <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                   class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Login to Apply
                </a>
            
            <?php elseif ($is_volunteer): ?>
                <?php if (!$application && $opp['status'] === 'open' && !$deadline_passed && ($total_slots == 0 || $slots_filled < $total_slots)): ?>
                    <button type="button"
                            class="btn-primary btn-apply"
                            onclick="openCommitmentModal(() => {
                                window.location.href = 'apply_opportunity.php?id=<?= $opportunity_id ?>';
                            })">
                        <i class="fas fa-paper-plane"></i> Apply Now
                    </button>

                
                <?php elseif ($can_reapply): ?>
                    <button type="button"
                            class="btn-primary btn-reapply"
                            onclick="openCommitmentModal(() => {
                                window.location.href = 'apply_opportunity.php?id=<?= $opportunity_id ?>';
                            })">
                        <i class="fas fa-redo"></i> Reapply
                    </button>
                
                <?php elseif ($application && in_array($application['status'], ['pending','shortlisted'])): ?>
                    <a href="withdraw_application.php?id=<?= $application['application_id'] ?>"
                       class="btn-danger"
                       onclick="return confirm('Are you sure you want to withdraw your application?')">
                        <i class="fas fa-sign-out-alt"></i> Withdraw
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($is_owner && isset($owner_actions[$opp['status']])): ?>
                <?php foreach ($owner_actions[$opp['status']] as $btn): ?>
                    <a href="change_status_opp.php?id=<?= $opportunity_id ?>&action=<?= $btn['action'] ?>"
                    class="<?= $btn['class'] ?>"
                    onclick="return confirm('<?= $btn['confirm'] ?>')">
                        <i class="<?= $btn['icon'] ?>"></i> <?= $btn['label'] ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Main Content -->
    <div class="vc-opportunity-content">
        <!-- Left Column: Main Content -->
        <main class="vc-main-content">
            <!-- Gallery -->
            <?php if (!empty($images)): ?>
                <section class="content-section gallery-section">
                    <h2><i class="fas fa-images"></i> Photos</h2>
                    <div class="gallery-grid">
                        <?php foreach ($images as $index => $img): ?>
                            <div class="gallery-item" onclick="vcOpenGallery(<?= $index ?>)">
                                <img src="<?= htmlspecialchars($img['image_url']) ?>" 
                                     alt="Gallery image <?= $index + 1 ?>"
                                     loading="lazy">
                                <div class="gallery-overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Description -->
            <section class="content-section">
                <h2><i class="fas fa-align-left"></i> About This Opportunity</h2>
                <div class="content-rich">
                    <?= nl2br(htmlspecialchars($opp['description'] ?? 'No description available.')) ?>
                </div>
            </section>

            <!-- Responsibilities -->
            <?php if (!empty($opp['responsibilities'])): ?>
                <section class="content-section">
                    <h2><i class="fas fa-tasks"></i> Your Responsibilities</h2>
                    <div class="content-rich">
                        <?= nl2br($opp['responsibilities']) ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Requirements -->
            <?php if (!empty($opp['requirements'])): ?>
                <section class="content-section highlight-box">
                    <h2><i class="fas fa-clipboard-check"></i> Requirements & Eligibility</h2>
                    <div class="content-rich">
                        <?= nl2br(htmlspecialchars($opp['requirements'])) ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Skills & Interests -->
            <?php if (!empty($skills) || !empty($interests)): ?>
                <section class="content-section">
                    <div class="skills-interests-grid">
                        <?php if (!empty($skills)): ?>
                            <div class="skill-interest-section">
                                <h3><i class="fas fa-tools"></i> Skills Required</h3>
                                <div class="tag-list">
                                    <?php foreach ($skills as $s): ?>
                                        <span class="tag"><?= htmlspecialchars($s['skill_name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($interests)): ?>
                            <div class="skill-interest-section">
                                <h3><i class="fas fa-heart"></i> Related Interests</h3>
                                <div class="tag-list">
                                    <?php foreach ($interests as $i): ?>
                                        <span class="tag"><?= htmlspecialchars($i['interest_name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Benefits -->
            <?php if (!empty($opp['benefits'])): ?>
                <section class="content-section highlight-box">
                    <h2><i class="fas fa-gift"></i> What You'll Gain</h2>
                    <div class="content-rich">
                        <?= nl2br(htmlspecialchars($opp['benefits'])) ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Safety & Logistics -->
            <?php if (!empty($opp['safety_notes']) || !empty($opp['transportation_info'])): ?>
                <section class="content-section warning-box">
                    <h2><i class="fas fa-shield-alt"></i> Safety & Logistics</h2>
                    <?php if (!empty($opp['safety_notes'])): ?>
                        <div class="safety-notes">
                            <h4><i class="fas fa-exclamation-triangle"></i> Safety Notes</h4>
                            <div class="content-rich">
                                <?= nl2br(htmlspecialchars($opp['safety_notes'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($opp['transportation_info'])): ?>
                        <div class="transportation-info">
                            <h4><i class="fas fa-bus"></i> Transportation & Location</h4>
                            <div class="content-rich">
                                <?= nl2br(htmlspecialchars($opp['transportation_info'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>

        <!-- Right Column: Sidebar -->
        <aside class="vc-sidebar">
            <!-- Application Status Card -->
            <div class="sidebar-card status-card">
                <h3><i class="fas fa-info-circle"></i> Application Status</h3>
                <?php if ($app_status_text): ?>
                    <div class="application-status-detail">
                        <div class="status-icon-large"><?= $status_icon ?></div>
                        <div class="status-message"><?= $app_status_text ?></div>
                        <?php if ($can_reapply): ?>
                            <a href="apply_opportunity.php?id=<?= $opportunity_id ?>" 
                               class="btn-reapply-small">
                                <i class="fas fa-redo"></i> Reapply Now
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="application-status-detail">
                        <?php if ($deadline_passed): ?>
                            <div class="status-icon-large danger">❌</div>
                            <div class="status-message">Application deadline has passed</div>
                        <?php elseif ($opp['status'] !== 'open'): ?>
                            <div class="status-icon-large">⏸️</div>
                            <div class="status-message">Applications are currently closed</div>
                        <?php else: ?>
                            <div class="status-icon-large success">✅</div>
                            <div class="status-message">Applications are open</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Organization Card -->
            <div class="sidebar-card">
                <h3><i class="fas fa-building"></i> Organization</h3>
                <div class="org-card-content">
                    <div class="org-logo-sidebar">
                        <?php if (!empty($opp['org_logo'])): ?>
                            <img src="<?= htmlspecialchars($opp['org_logo']) ?>" 
                                 alt="<?= htmlspecialchars($opp['org_name']) ?>">
                        <?php else: ?>
                            <div class="org-logo-placeholder">
                                <?= strtoupper(substr($opp['org_name'] ?? 'O', 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="org-details-sidebar">
                        <strong><?= htmlspecialchars($opp['org_name']) ?></strong>
                        <a href="profile_org.php?id=<?= $opp['org_id'] ?>" class="view-profile">
                            View Profile <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <?php if (!empty($contacts)): ?>
                <div class="sidebar-card">
                    <h3><i class="fas fa-address-book"></i> Contact Information</h3>
                    <div class="contacts-list">
                        <?php foreach ($contacts as $c): ?>
                            <div class="contact-item <?= $c['is_primary'] ? 'primary-contact' : '' ?>">
                                <strong><?= htmlspecialchars($c['contact_name']) ?></strong>
                                <?php if ($c['is_primary']): ?>
                                    <span class="primary-badge">Primary</span>
                                <?php endif; ?>
                                <?php if (!empty($c['contact_email'])): ?>
                                    <div class="contact-detail">
                                        <i class="fas fa-envelope"></i>
                                        <?= htmlspecialchars($c['contact_email']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($c['contact_phone'])): ?>
                                    <div class="contact-detail">
                                        <i class="fas fa-phone"></i>
                                        <?= htmlspecialchars($c['contact_phone']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Facts -->
            <div class="sidebar-card">
                <h3><i class="fas fa-chart-bar"></i> Quick Facts</h3>
                <div class="quick-facts-list">
                    <div class="quick-fact">
                        <span class="fact-label">Status</span>
                        <span class="fact-value status-<?= htmlspecialchars($opp['status']) ?>">
                            <?= ucfirst(htmlspecialchars($opp['status'])) ?>
                        </span>
                    </div>
                    <div class="quick-fact">
                        <span class="fact-label">Posted</span>
                        <span class="fact-value"><?= date('M d, Y', strtotime($opp['created_at'])) ?></span>
                    </div>
                    <div class="quick-fact">
                        <span class="fact-label">Updated</span>
                        <span class="fact-value"><?= date('M d, Y', strtotime($opp['updated_at'])) ?></span>
                    </div>
                    <?php if (!empty($opp['min_age'])): ?>
                        <div class="quick-fact">
                            <span class="fact-label">Minimum Age</span>
                            <span class="fact-value"><?= $opp['min_age'] ?> years</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Share Card -->
            <div class="sidebar-card share-card">
                <h3><i class="fas fa-share-alt"></i> Share Opportunity</h3>
                <div class="share-buttons">
                    <button class="share-btn facebook" onclick="shareOnPlatform('facebook')">
                        <i class="fab fa-facebook-f"></i>
                    </button>
                    <button class="share-btn twitter" onclick="shareOnPlatform('twitter')">
                        <i class="fab fa-twitter"></i>
                    </button>
                    <button class="share-btn linkedin" onclick="shareOnPlatform('linkedin')">
                        <i class="fab fa-linkedin-in"></i>
                    </button>
                    <button class="share-btn whatsapp" onclick="shareOnPlatform('whatsapp')">
                        <i class="fab fa-whatsapp"></i>
                    </button>
                </div>
                <div class="copy-link">
                    <input type="text" id="shareUrl" value="<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" readonly>
                    <button class="btn-copy" onclick="copyShareLink()">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- Opportunity Ratings Modal -->
<div id="opportunityRatingsModal" class="vc-modal">
    <div class="vc-modal-overlay" onclick="closeOpportunityRatingsModal()"></div>
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-star"></i> 
                Ratings for <?= htmlspecialchars($opp['title'] ?? 'Opportunity') ?>
                <span class="vc-rating-score"><?= $opp_avg_rating ?></span>
            </h3>
            <button class="vc-modal-close" onclick="closeOpportunityRatingsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Reviews Stats -->
        <div class="vc-reviews-stats">
            <div class="vc-rating-distribution">
                <div class="vc-rating-bars">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                    <div class="vc-rating-bar-row">
                        <span class="vc-rating-label"><?= $i ?> <i class="fas fa-star"></i></span>
                        <div class="vc-rating-bar-bg">
                            <div class="vc-rating-bar-fill" 
                                 style="width: <?= $opp_rating_count > 0 ? ($opp_review_stats['counts'][$i] / $opp_rating_count * 100) : 0 ?>%">
                            </div>
                        </div>
                        <span class="vc-rating-count"><?= $opp_review_stats['counts'][$i] ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="vc-reviews-summary">
                <div class="vc-total-reviews">
                    <div class="vc-total-number"><?= $opp_rating_count ?></div>
                    <div class="vc-total-label">Total Reviews</div>
                </div>
                <div class="vc-avg-rating">
                    <div class="vc-avg-number"><?= $opp_avg_rating ?></div>
                    <div class="vc-avg-label">Average Rating</div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="vc-reviews-filters">
            <div class="vc-filter-group">
                <label>Sort by:</label>
                <select id="sortReviews" onchange="filterOpportunityReviews()">
                    <option value="latest">Latest</option>
                    <option value="highest">Highest Rating</option>
                    <option value="lowest">Lowest Rating</option>
                </select>
            </div>
            
            <div class="vc-filter-group">
                <label>
                    <input type="checkbox" id="filterWithComments" onchange="filterOpportunityReviews()">
                    Show only reviews with comments
                </label>
            </div>
            
            <div class="vc-filter-group">
                <label>Rating:</label>
                <select id="filterRating" onchange="filterOpportunityReviews()">
                    <option value="all">All Ratings</option>
                    <option value="5">5 Stars</option>
                    <option value="4">4 Stars</option>
                    <option value="3">3 Stars</option>
                    <option value="2">2 Stars</option>
                    <option value="1">1 Star</option>
                </select>
            </div>
        </div>
        
        <!-- Reviews List -->
        <div class="vc-reviews-list" id="opportunityReviewsList">
            <?php if (!empty($opportunity_ratings)): ?>
                <?php foreach ($opportunity_ratings as $review): ?>
                <div class="vc-review-card" 
                     data-rating="<?= $review['rating'] ?>"
                     data-date="<?= strtotime($review['created_at']) ?>"
                     data-has-comment="<?= !empty(trim($review['review_text'] ?? '')) ? 'true' : 'false' ?>">
                    <div class="vc-review-header">
                        <div class="vc-reviewer-info">
                            <div class="vc-reviewer-name">
                                <?= htmlspecialchars($review['reviewer_name']) ?>
                                <span class="vc-reviewer-type">
                                    (<?= ucfirst($review['reviewer_type_label']) ?>)
                                </span>
                            </div>
                            <div class="vc-review-date">
                                <?= date('M d, Y', strtotime($review['created_at'])) ?>
                            </div>
                        </div>
                        
                        <div class="vc-review-meta">
                            <div class="vc-review-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <?php if ($i <= $review['rating']): ?>
                                        <i class="fas fa-star vc-star-filled"></i>
                                    <?php else: ?>
                                        <i class="far fa-star vc-star-empty"></i>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <span class="vc-rating-value"><?= $review['rating'] ?>.0</span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty(trim($review['review_text']))): ?>
                    <div class="vc-review-body">
                        <p><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="vc-empty-reviews">
                    <i class="fas fa-comment-slash"></i>
                    <p>No reviews yet for this opportunity</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="vc-modal-footer">
            <button class="vc-btn vc-btn-secondary" onclick="closeOpportunityRatingsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Gallery Modal -->
<div class="gallery-modal" id="galleryModal">
    <div class="modal-content">
        <button class="modal-close" onclick="vcCloseGallery()">×</button>
        <button class="modal-nav prev" onclick="navigateGallery(-1)">‹</button>
        <img id="modalImage" src="" alt="">
        <button class="modal-nav next" onclick="navigateGallery(1)">›</button>
        <div class="modal-caption">Image <span id="imageIndex">1</span> of <?= count($images) ?></div>
    </div>
</div>

<?php include __DIR__ . '/views/components/volunteer_commitment_modal.php'; ?>


<script>
// Gallery functionality
const galleryImages = <?= json_encode($images) ?>;
let currentGalleryIndex = 0;

function vcOpenGallery(index) {
    currentGalleryIndex = index;
    const image = galleryImages[index];
    document.getElementById('modalImage').src = image.image_url;
    document.getElementById('imageIndex').textContent = index + 1;
    document.getElementById('galleryModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function vcCloseGallery() {
    document.getElementById('galleryModal').classList.remove('active');
    document.body.style.overflow = '';
}

function navigateGallery(direction) {
    currentGalleryIndex = (currentGalleryIndex + direction + galleryImages.length) % galleryImages.length;
    vcOpenGallery(currentGalleryIndex);
}

// Gallery keyboard navigation
document.addEventListener('keydown', (e) => {
    const modal = document.getElementById('galleryModal');
    if (modal.classList.contains('active')) {
        if (e.key === 'Escape') vcCloseGallery();
        if (e.key === 'ArrowLeft') navigateGallery(-1);
        if (e.key === 'ArrowRight') navigateGallery(1);
    }
});

// Share functionality
function vcShareOpportunity() {
    const shareUrl = document.getElementById('shareUrl').value;
    navigator.clipboard.writeText(shareUrl).then(() => {
        alert('Link copied to clipboard!');
    });
}

function shareOnPlatform(platform) {
    const url = encodeURIComponent(window.location.href);
    const title = encodeURIComponent(document.title);
    const text = encodeURIComponent("Check out this volunteer opportunity!");
    
    let shareUrl = '';
    switch(platform) {
        case 'facebook':
            shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
            break;
        case 'twitter':
            shareUrl = `https://twitter.com/intent/tweet?url=${url}&text=${text}`;
            break;
        case 'linkedin':
            shareUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${url}`;
            break;
        case 'whatsapp':
            shareUrl = `https://wa.me/?text=${text}%20${url}`;
            break;
    }
    
    window.open(shareUrl, '_blank', 'width=600,height=400');
}

function copyShareLink() {
    const input = document.getElementById('shareUrl');
    input.select();
    document.execCommand('copy');
    
    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check"></i>';
    button.style.background = '#10b981';
    
    setTimeout(() => {
        button.innerHTML = originalHTML;
        button.style.background = '';
    }, 2000);
}
</script>

<script>
// Opportunity Ratings Modal Functions
function openOpportunityRatingsModal() {
    const modal = document.getElementById('opportunityRatingsModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    // Reset filters
    document.getElementById('sortReviews').value = 'latest';
    document.getElementById('filterWithComments').checked = false;
    document.getElementById('filterRating').value = 'all';
    // Apply initial sorting
    filterOpportunityReviews();
}

function closeOpportunityRatingsModal() {
    const modal = document.getElementById('opportunityRatingsModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Filter and Sort Reviews
function filterOpportunityReviews() {
    const sortBy = document.getElementById('sortReviews').value;
    const showOnlyWithComments = document.getElementById('filterWithComments').checked;
    const filterRating = document.getElementById('filterRating').value;
    
    const reviews = document.querySelectorAll('#opportunityReviewsList .vc-review-card');
    const reviewsArray = Array.from(reviews);
    const reviewsList = document.getElementById('opportunityReviewsList');
    
    // First, hide all reviews
    reviews.forEach(review => {
        review.style.display = 'none';
    });
    
    // Filter
    let filteredReviews = reviewsArray.filter(review => {
        const hasComment = review.dataset.hasComment === 'true';
        const rating = review.dataset.rating;
        
        // Filter by comments
        if (showOnlyWithComments && !hasComment) {
            return false;
        }
        
        // Filter by rating
        if (filterRating !== 'all' && rating !== filterRating) {
            return false;
        }
        
        return true;
    });
    
    // Sort
    filteredReviews.sort((a, b) => {
        const aRating = parseInt(a.dataset.rating);
        const bRating = parseInt(b.dataset.rating);
        const aDate = parseInt(a.dataset.date);
        const bDate = parseInt(b.dataset.date);
        
        switch (sortBy) {
            case 'latest':
                return bDate - aDate; // Newest first
            case 'highest':
                return bRating - aRating; // Highest rating first
            case 'lowest':
                return aRating - bRating; // Lowest rating first
            default:
                return bDate - aDate;
        }
    });
    
    // Show filtered reviews and reorder them
    filteredReviews.forEach(review => {
        review.style.display = 'block';
        reviewsList.appendChild(review);
    });
    
    // Show empty state if no reviews match filters
    const existingEmptyState = document.querySelector('#opportunityReviewsList .vc-filter-empty-state');
    if (existingEmptyState) {
        existingEmptyState.remove();
    }
    
    if (filteredReviews.length === 0 && reviewsArray.length > 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'vc-filter-empty-state vc-empty-reviews';
        emptyDiv.innerHTML = `
            <i class="fas fa-filter"></i>
            <p>No reviews match your filters</p>
            <button class="vc-btn vc-btn-secondary mt-2" onclick="resetOpportunityFilters()">
                Reset Filters
            </button>
        `;
        reviewsList.appendChild(emptyDiv);
    }
}

// Reset filters to show all reviews
function resetOpportunityFilters() {
    document.getElementById('sortReviews').value = 'latest';
    document.getElementById('filterWithComments').checked = false;
    document.getElementById('filterRating').value = 'all';
    filterOpportunityReviews();
}

// Update ESC key handler
document.addEventListener('keydown', (e) => {
    const galleryModal = document.getElementById('galleryModal');
    const ratingsModal = document.getElementById('opportunityRatingsModal');
    
    if (e.key === 'Escape') {
        if (galleryModal.classList.contains('active')) {
            vcCloseGallery();
        }
        if (ratingsModal.classList.contains('active')) {
            closeOpportunityRatingsModal();
        }
    }
});

// Update click outside handler
document.addEventListener('click', function(e) {
    const ratingsModal = document.getElementById('opportunityRatingsModal');
    if (e.target === ratingsModal || e.target.classList.contains('vc-modal-overlay')) {
        closeOpportunityRatingsModal();
    }
});
</script>

<?php require_once __DIR__ . "/views/layout/footer.php"; ?>