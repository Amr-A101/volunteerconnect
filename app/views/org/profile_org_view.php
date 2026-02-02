<?php

// Decode JSON safely
$contact = $org['contact_info'] ?? [];
$links   = $org['external_links'] ?? [];
$docs    = $org['document_paths'] ?? [];

$contactPhone = $contact['phone'] ?? null;
$contactEmail = $contact['email'] ?? null;

// icon for organization badge
$org_icon = '<i class="fa-solid fa-building-columns vc-org-icon"></i>';

// Get current tab from URL
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

// Fetch reviews for this organization
$org_reviews = [];
$org_review_stats = [
    'total' => 0,
    'average' => $avg_rating,
    'counts' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]
];

if (isset($org['org_id'])) {
    // Get all reviews for this organization (as reviewee)
    $org_review_stmt = $dbc->prepare("
        SELECT r.*, 
               CASE 
                   WHEN r.reviewer_type = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
                   WHEN r.reviewer_type = 'organization' THEN o.name
               END as reviewer_name,
               CASE 
                   WHEN r.reviewer_type = 'volunteer' THEN 'volunteer'
                   WHEN r.reviewer_type = 'organization' THEN 'organization'
               END as reviewer_type_label,
               opp.title as opportunity_title,
               opp.opportunity_id
        FROM reviews r
        LEFT JOIN volunteers v ON r.reviewer_type = 'volunteer' AND r.reviewer_id = v.vol_id
        LEFT JOIN organizations o ON r.reviewer_type = 'organization' AND r.reviewer_id = o.org_id
        LEFT JOIN opportunities opp ON r.opportunity_id = opp.opportunity_id
        WHERE r.reviewee_type = 'organization' 
        AND r.reviewee_id = ?
        ORDER BY r.created_at DESC
    ");
    $org_review_stmt->bind_param("i", $org['org_id']);
    $org_review_stmt->execute();
    $org_review_result = $org_review_stmt->get_result();
    
    while ($review = $org_review_result->fetch_assoc()) {
        $org_reviews[] = $review;
        $org_review_stats['counts'][$review['rating']]++;
    }
    $org_review_stmt->close();
    
    $org_review_stats['total'] = count($org_reviews);


    $completed_opp_reviews = [];
    foreach ($completed_opportunities as $opp) {
        $review_stmt = $dbc->prepare("
            SELECT r.*, 
                CASE 
                    WHEN r.reviewer_type = 'volunteer' THEN CONCAT(v.first_name, ' ', v.last_name)
                    ELSE o.name
                END as reviewer_name,
                r.reviewer_type,
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
        $review_stmt->bind_param("ii", $opp['opportunity_id'], $org['org_id']);
        $review_stmt->execute();
        $review_result = $review_stmt->get_result();
        
        $opp_reviews = [];
        while ($review = $review_result->fetch_assoc()) {
            $opp_reviews[] = $review;
        }
        $review_stmt->close();
        
        $completed_opp_reviews[$opp['opportunity_id']] = $opp_reviews;
    }
}
?>

<link rel="stylesheet" href="/volcon/assets/css/profile_base.css">
<link rel="stylesheet" href="/volcon/assets/css/profile_org.css">
<script src="/volcon/assets/js/utils/scroll-to-top.js"></script>

<!-- profile page - organization -->
<div class="vc-pro-page">

    <!-- TOP FLOATING BAR -->
    <div class="vc-pro-topbar">
        <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'dashboard_vol.php') ?>" class="vc-pro-btn-light">← Back</a>

        <?php if ($is_self): ?>
            <a href="/volcon/app/update_profile_org.php" class="vc-pro-btn-primary">Edit Profile</a>

        <?php elseif ($is_vol || $is_org): ?>
            <button 
                class="vc-pro-btn-primary"
                onclick="startChatWith(
                    <?= (int)$org['org_id'] ?>,
                    'org',
                    '<?= esc($org['name']) ?>',
                    '<?= esc($org['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png') ?>'
                )">
                <i class="fas fa-comment"></i> Send Message
            </button>

        <?php else: ?>
            <a href="/volcon/index.php" class="vc-pro-btn-light">← Home</a>
        <?php endif; ?>
    </div>

    <!-- HEADER / HERO -->
    <div class="vc-pro-hero vc-org-hero">

        <div class="vc-pro-avatar-wrapper">
            <img src="<?= $org['profile_picture'] ?: '/volcon/assets/uploads/default-org.png'; ?>"
                 class="vc-pro-avatar">
        </div>

        <div class="vc-pro-content">
            <div>
                <h1 class="vc-pro-name">
                    <?= $org_icon ?>
                    <?= htmlspecialchars($org['name']) ?>
                </h1>

                <p class="vc-pro-details">
                    @<?= htmlspecialchars($org['username']); ?><br>
                    <?= htmlspecialchars($org['email']); ?>
                </p>
            </div>

            <div class="vc-pro-subcontent">
                <p class="vc-pro-location">
                    <i class="fa-solid fa-location-dot"></i>
                    <?= htmlspecialchars($org['city']); ?>,
                    <?= htmlspecialchars($org['state']); ?>,
                    <?= htmlspecialchars($org['country']); ?>
                </p>
                
                <!-- Rating Stars -->
                <?php if ($rating_count > 0): ?>
                <div class="vc-org-rating" onclick="openReviewsModal()" style="cursor: pointer;">
                    <div class="vc-rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php if ($i <= floor($avg_rating)): ?>
                                <i class="fas fa-star vc-star-filled"></i>
                            <?php elseif ($i - 0.5 <= $avg_rating): ?>
                                <i class="fas fa-star-half-alt vc-star-filled"></i>
                            <?php else: ?>
                                <i class="far fa-star vc-star-empty"></i>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <span class="vc-rating-score"><?= $avg_rating ?></span>
                        <i class="fas fa-external-link-alt vc-rating-link"></i>
                    </div>
                    <div class="vc-rating-count">
                        (<?= $rating_count ?> review<?= $rating_count != 1 ? 's' : '' ?>)
                    </div>
                </div>
                <?php else: ?>
                <div class="vc-org-rating" onclick="openReviewsModal()" style="cursor: pointer;">
                    <div class="vc-rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star vc-star-empty"></i>
                        <?php endfor; ?>
                        <span class="vc-rating-score">No ratings yet</span>
                        <i class="fas fa-external-link-alt vc-rating-link"></i>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="vc-org-tabs">
        <a href="?id=<?= $org['org_id'] ?>&tab=profile" 
           class="vc-org-tab <?= $current_tab === 'profile' ? 'active' : '' ?>">
            <i class="fas fa-info-circle"></i> Profile Info
        </a>
        <a href="?id=<?= $org['org_id'] ?>&tab=opportunities" 
           class="vc-org-tab <?= $current_tab === 'opportunities' ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i> Opportunities
            <span class="vc-tab-badge"><?= count($open_opportunities ?? []) + count($completed_opportunities ?? []) ?></span>
        </a>
    </div>

    <!-- TAB CONTENT -->
    <div class="vc-org-tab-content">
        <?php if ($current_tab === 'profile'): ?>
            <!-- PROFILE INFO TAB -->
            
            <!-- ABOUT / DESCRIPTION -->
            <?php if (!empty($org['description'])): ?>
            <div class="vc-pro-card">
                <h3>About</h3>
                <p><?= nl2br(htmlspecialchars($org['description'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- MISSION -->
            <?php if (!empty($org['mission'])): ?>
            <div class="vc-pro-card">
                <h3>Mission</h3>
                <p><?= nl2br(htmlspecialchars($org['mission'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- CONTACT INFO -->
            <div class="vc-pro-card">
                <h3>Contact</h3>
                <?php if (!empty($contactPhone) || !empty($contactEmail)): ?>
                    <?php if (!empty($contactPhone)): ?>
                        <p><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($contactPhone); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($contactEmail)): ?>
                        <p><i class="fa-solid fa-envelope"></i> <a href="mailto:<?= htmlspecialchars($contactEmail); ?>"><?= htmlspecialchars($contactEmail); ?></a></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No contact information provided.</p>
                <?php endif; ?>
            </div>

            <!-- ADDRESS -->
            <div class="vc-pro-card">
                <h3>Address</h3>
                <p>
                    <?= htmlspecialchars($org['address']); ?><br>
                    <?= htmlspecialchars($org['postcode']); ?> <?= htmlspecialchars($org['city']); ?><br>
                    <?= htmlspecialchars($org['state']); ?>, <?= htmlspecialchars($org['country']); ?>
                </p>
            </div>

            <!-- EXTERNAL LINKS -->
            <?php if (!empty($links)): ?>
            <div class="vc-pro-card">
                <h3>External Links</h3>
                <div class="vc-pro-pill-grid">
                    <?php foreach ($links as $label => $url): ?>
                        <a class="vc-pro-pill vc-pro-link-pill" href="<?= htmlspecialchars($url) ?>" target="_blank">
                            <i class="fa-solid fa-link"></i>
                            <?= htmlspecialchars($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- DOCUMENTS -->
            <?php if (!empty($docs)): ?>
            <div class="vc-pro-card">
                <h3>Documents</h3>
                <ul class="vc-pro-doc-list">
                    <?php foreach ($docs as $label => $path): ?>
                        <li>
                            <a href="<?= htmlspecialchars($path) ?>" target="_blank">
                                <i class="fa-solid fa-file"></i>
                                <?= htmlspecialchars($label) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

        <?php elseif ($current_tab === 'opportunities'): ?>
        <!-- OPPORTUNITIES TAB -->
            <!-- OPEN OPPORTUNITIES -->
            <?php if (!empty($open_opportunities)): ?>
            <div class="vc-opp-section">
                <h3 class="vc-section-title">
                    <i class="fas fa-door-open"></i> Open Opportunities
                    <span class="vc-section-count"><?= count($open_opportunities) ?></span>
                </h3>
                
                <div class="vc-opp-grid">
                    <?php foreach ($open_opportunities as $opp): ?>
                    <div class="vc-opp-card">
                        <div class="vc-opp-header">
                            <h4 class="vc-opp-title">
                                <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>">
                                    <?= htmlspecialchars($opp['title']) ?>
                                </a>
                            </h4>
                            <span class="vc-badge vc-status-open">Open</span>
                        </div>
                        
                        <div class="vc-opp-meta">
                            <?php if ($opp['start_date']): ?>
                            <span><i class="fas fa-calendar"></i> 
                                <?= date('M d, Y', strtotime($opp['start_date'])) ?>
                                <?php if ($opp['end_date'] && $opp['end_date'] != $opp['start_date']): ?>
                                    - <?= date('M d, Y', strtotime($opp['end_date'])) ?>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            
                            <?php if ($opp['city']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> 
                                <?= htmlspecialchars($opp['city']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($opp['brief_summary'])): ?>
                        <p class="vc-opp-summary"><?= htmlspecialchars($opp['brief_summary']) ?></p>
                        <?php endif; ?>
                        
                        <div class="vc-opp-footer">
                            <div class="vc-opp-slots">
                                <i class="fas fa-users"></i>
                                <?php if ($opp['number_of_volunteers']): ?>
                                    <span><?= $opp['number_of_volunteers'] ?> slots</span>
                                <?php else: ?>
                                    <span>Unlimited slots</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="vc-opp-actions">
                                <?php if ($is_vol): ?>
                                    <!-- Volunteer can apply -->
                                    <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>" 
                                       class="vc-btn vc-btn-primary vc-btn-sm">
                                        Read More
                                    </a>
                                <?php elseif ($is_self): ?>
                                    <!-- Organization can manage -->
                                    <a href="applicants_manager.php?id=<?= $opp['opportunity_id'] ?>" 
                                       class="vc-btn vc-btn-secondary vc-btn-sm">
                                        Manage Applicants
                                    </a>
                                <?php else: ?>
                                    <!-- Other orgs can view -->
                                    <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>" 
                                       class="vc-btn vc-btn-secondary vc-btn-sm">
                                        View Details
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="vc-empty-state">
                <div class="vc-empty-icon">📭</div>
                <h3>No Open Opportunities</h3>
                <p>This organization doesn't have any open opportunities at the moment.</p>
            </div>
            <?php endif; ?>
            
            <!-- COMPLETED OPPORTUNITIES -->
            <?php if (!empty($completed_opportunities)): ?>
            <div class="vc-opp-section">
                <h3 class="vc-section-title">
                    <i class="fas fa-check-circle"></i> Completed Opportunities
                    <span class="vc-section-count"><?= count($completed_opportunities) ?></span>
                </h3>
                
                <div class="vc-opp-grid">
                    <?php foreach ($completed_opportunities as $opp): ?>
                    <div class="vc-opp-card">
                        <div class="vc-opp-header">
                            <h4 class="vc-opp-title">
                                <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>">
                                    <?= htmlspecialchars($opp['title']) ?>
                                </a>
                            </h4>
                            <span class="vc-badge vc-status-completed">Completed</span>
                        </div>
                        
                        <div class="vc-opp-meta">
                            <?php if ($opp['start_date']): ?>
                            <span><i class="fas fa-calendar"></i> 
                                <?= date('M d, Y', strtotime($opp['start_date'])) ?>
                                <?php if ($opp['end_date'] && $opp['end_date'] != $opp['start_date']): ?>
                                    - <?= date('M d, Y', strtotime($opp['end_date'])) ?>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            
                            <?php if ($opp['city']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> 
                                <?= htmlspecialchars($opp['city']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Rating for completed opportunities -->
                        <?php if ($opp['avg_rating'] > 0): ?>
                        <div class="vc-opp-rating">
                            <div class="vc-rating-stars vc-rating-small">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <?php if ($i <= floor($opp['avg_rating'])): ?>
                                        <i class="fas fa-star vc-star-filled"></i>
                                    <?php elseif ($i - 0.5 <= $opp['avg_rating']): ?>
                                        <i class="fas fa-star-half-alt vc-star-filled"></i>
                                    <?php else: ?>
                                        <i class="far fa-star vc-star-empty"></i>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <span class="vc-rating-score"><?= round($opp['avg_rating'], 1) ?></span>
                                <span class="vc-rating-count">
                                    (<?= $opp['rating_count'] ?>)
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="vc-opp-footer">
                            <?php if ($is_vol && $opp['status'] === 'completed'): ?>
                                <!-- Volunteer can rate if they participated -->
                                <?php 
                                // Check if volunteer participated in this opportunity
                                $participated = false;
                                if (isset($_SESSION['vol_id'])) {
                                    $part_stmt = $dbc->prepare("
                                        SELECT 1 FROM participation p
                                        JOIN applications a ON p.volunteer_id = a.volunteer_id AND p.opportunity_id = a.opportunity_id
                                        WHERE p.volunteer_id = ? AND p.opportunity_id = ? AND p.status = 'attended'
                                    ");
                                    $part_stmt->bind_param("ii", $_SESSION['vol_id'], $opp['opportunity_id']);
                                    $part_stmt->execute();
                                    $participated = $part_stmt->get_result()->num_rows > 0;
                                    $part_stmt->close();
                                }
                                ?>
                                
                                <?php if ($participated): ?>
                                    <!-- Check if already reviewed -->
                                    <?php 
                                    $reviewed = false;
                                    if (isset($_SESSION['vol_id'])) {
                                        $rev_stmt = $dbc->prepare("
                                            SELECT 1 FROM reviews 
                                            WHERE reviewer_type = 'volunteer' 
                                            AND reviewer_id = ? 
                                            AND opportunity_id = ?
                                            AND reviewee_type = 'organization'
                                            AND reviewee_id = ?
                                        ");
                                        $rev_stmt->bind_param("iii", $_SESSION['vol_id'], $opp['opportunity_id'], $org['org_id']);
                                        $rev_stmt->execute();
                                        $reviewed = $rev_stmt->get_result()->num_rows > 0;
                                        $rev_stmt->close();
                                    }
                                    ?>
                                    
                                    <?php if (!$reviewed): ?>
                                    <button class="vc-btn vc-btn-warning vc-btn-sm"
                                            onclick="rateOpportunity(<?= $opp['opportunity_id'] ?>, <?= $org['org_id'] ?>)">
                                        <i class="fas fa-star"></i> Rate Experience
                                    </button>
                                    <?php else: ?>
                                    <span class="vc-rated-badge">
                                        <i class="fas fa-check-circle"></i> Rated
                                    </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div class="vc-opp-actions">
                                <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>" 
                                class="vc-btn vc-btn-secondary vc-btn-sm">
                                    View Details
                                </a>
                                <?php if ($opp['rating_count'] > 0): ?>
                                <button class="vc-btn vc-btn-primary vc-btn-sm"
                                        onclick="viewOpportunityRatings(
                                            <?= $opp['opportunity_id'] ?>, 
                                            '<?= htmlspecialchars($opp['title']) ?>',
                                            <?= htmlspecialchars(json_encode($completed_opp_reviews[$opp['opportunity_id']] ?? [])) ?>
                                        )">
                                    <i class="fas fa-star"></i> View Ratings (<?= $opp['rating_count'] ?>)
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</div>

<!-- Ratings Modal for Specific Opportunity -->
<div id="ratingsModal" class="vc-modal">
    <div class="vc-modal-overlay" onclick="closeRatingsModal()"></div>
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-star"></i> 
                <span id="modalOppTitle"></span>
            </h3>
            <button class="vc-modal-close" onclick="closeRatingsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="vc-modal-body">
            <div id="ratingsList" class="vc-ratings-list">
                <!-- Ratings will be loaded here -->
            </div>
            
            <div class="vc-no-ratings" id="noRatingsMessage" style="display: none;">
                <i class="fas fa-star"></i>
                <p>No ratings yet for this opportunity</p>
            </div>
        </div>
        
        <div class="vc-modal-footer">
            <button class="vc-btn vc-btn-secondary" onclick="closeRatingsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Reviews Modal for All Ratings -->
<div id="reviewsModal" class="vc-modal">
    <div class="vc-modal-overlay" onclick="closeReviewsModal()"></div>
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-star"></i> 
                Reviews for <?= htmlspecialchars($org['name'] ?? 'Organization') ?>
                <span class="vc-rating-score"><?= number_format($avg_rating ?? 0, 1) ?></span>
            </h3>
            <button class="vc-modal-close" onclick="closeReviewsModal()">
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
                                 style="width: <?= $org_review_stats['total'] > 0 ? ($org_review_stats['counts'][$i] / $org_review_stats['total'] * 100) : 0 ?>%">
                            </div>
                        </div>
                        <span class="vc-rating-count"><?= $org_review_stats['counts'][$i] ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="vc-reviews-summary">
                <div class="vc-total-reviews">
                    <div class="vc-total-number"><?= $org_review_stats['total'] ?></div>
                    <div class="vc-total-label">Total Reviews</div>
                </div>
                <div class="vc-avg-rating">
                    <div class="vc-avg-number"><?= number_format($avg_rating ?? 0, 1) ?></div>
                    <div class="vc-avg-label">Average Rating</div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="vc-reviews-filters">
            <div class="vc-filter-group">
                <label>Sort by:</label>
                <select id="sortReviews" onchange="filterReviews()">
                    <option value="latest">Latest</option>
                    <option value="highest">Highest Rating</option>
                    <option value="lowest">Lowest Rating</option>
                </select>
            </div>
            
            <div class="vc-filter-group">
                <label>
                    <input type="checkbox" id="filterWithComments" onchange="filterReviews()">
                    Show only reviews with comments
                </label>
            </div>
            
            <div class="vc-filter-group">
                <label>Rating:</label>
                <select id="filterRating" onchange="filterReviews()">
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
        <div class="vc-reviews-list" id="reviewsList">
            <?php if (!empty($org_reviews)): ?>
                <?php foreach ($org_reviews as $review): ?>
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
                            <?php if ($review['opportunity_title']): ?>
                            <div class="vc-review-opportunity">
                                <i class="fas fa-calendar-alt"></i>
                                <?= htmlspecialchars($review['opportunity_title']) ?>
                            </div>
                            <?php endif; ?>
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
                            <div class="vc-review-date">
                                <?= date('M d, Y', strtotime($review['created_at'])) ?>
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
                    <p>No reviews yet</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="vc-modal-footer">
            <button class="vc-btn vc-btn-secondary" onclick="closeReviewsModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Modal Functions for Reviews
function openReviewsModal() {
    const modal = document.getElementById('reviewsModal');
    modal.classList.add('active');
    document.body.classList.add('modal-open');
    // Reset filters
    document.getElementById('sortReviews').value = 'latest';
    document.getElementById('filterWithComments').checked = false;
    document.getElementById('filterRating').value = 'all';
    // Apply initial sorting
    filterReviews();
}

function closeReviewsModal() {
    const modal = document.getElementById('reviewsModal');
    modal.classList.remove('active');
    document.body.classList.remove('modal-open');
}

// Filter and Sort Reviews
function filterReviews() {
    const sortBy = document.getElementById('sortReviews').value;
    const showOnlyWithComments = document.getElementById('filterWithComments').checked;
    const filterRating = document.getElementById('filterRating').value;
    
    const reviews = document.querySelectorAll('.vc-review-card');
    const reviewsArray = Array.from(reviews);
    const reviewsList = document.getElementById('reviewsList');
    
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
    const existingEmptyState = document.querySelector('.vc-filter-empty-state');
    if (existingEmptyState) {
        existingEmptyState.remove();
    }
    
    if (filteredReviews.length === 0 && reviewsArray.length > 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'vc-filter-empty-state vc-empty-reviews';
        emptyDiv.innerHTML = `
            <i class="fas fa-filter"></i>
            <p>No reviews match your filters</p>
            <button class="vc-btn vc-btn-secondary mt-2" onclick="resetFilters()">
                Reset Filters
            </button>
        `;
        reviewsList.appendChild(emptyDiv);
    }
}

// Reset filters to show all reviews
function resetFilters() {
    document.getElementById('sortReviews').value = 'latest';
    document.getElementById('filterWithComments').checked = false;
    document.getElementById('filterRating').value = 'all';
    filterReviews();
}

// Close modal with ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReviewsModal();
    }
});

// Close modal when clicking outside content
document.addEventListener('click', function(e) {
    const modal = document.getElementById('reviewsModal');
    if (e.target === modal || e.target.classList.contains('vc-modal-overlay')) {
        closeReviewsModal();
    }
});
</script>

<script>
// Ratings Modal for Specific Opportunity
function viewOpportunityRatings(oppId, oppTitle, reviewsData) {
    // Set modal title
    document.getElementById('modalOppTitle').textContent = 'Ratings: ' + oppTitle;
    
    // Show modal
    const modal = document.getElementById('ratingsModal');
    modal.classList.add('active');
    document.body.classList.add('modal-open');
    
    // Clear previous ratings
    const ratingsList = document.getElementById('ratingsList');
    ratingsList.innerHTML = '';
    document.getElementById('noRatingsMessage').style.display = 'none';
    
    // Parse the JSON data if it's a string
    let reviews;
    try {
        if (typeof reviewsData === 'string') {
            reviews = JSON.parse(reviewsData);
        } else {
            reviews = reviewsData;
        }
    } catch (e) {
        console.error('Error parsing reviews data:', e);
        reviews = [];
    }
    
    if (reviews && reviews.length > 0) {
        reviews.forEach(review => {
            const ratingDiv = document.createElement('div');
            ratingDiv.className = 'vc-rating-item';
            
            let starsHtml = '';
            for (let i = 1; i <= 5; i++) {
                if (i <= review.rating) {
                    starsHtml += '<i class="fas fa-star vc-star-filled"></i>';
                } else {
                    starsHtml += '<i class="far fa-star vc-star-empty"></i>';
                }
            }
            
            // Format date
            const date = new Date(review.created_at);
            const formattedDate = date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            
            ratingDiv.innerHTML = `
                <div class="vc-rating-header">
                    <div class="vc-rating-reviewer">
                        <strong>${review.reviewer_name || 'Anonymous'}</strong>
                        <span class="vc-reviewer-type">(${review.reviewer_type || 'volunteer'})</span>
                    </div>
                    <div class="vc-rating-meta">
                        <div class="vc-rating-stars">
                            ${starsHtml}
                            <span class="vc-rating-value">${review.rating}.0</span>
                        </div>
                        <div class="vc-rating-date">
                            ${formattedDate}
                        </div>
                    </div>
                </div>
                ${review.review_text ? `
                <div class="vc-rating-comment">
                    <p>${review.review_text}</p>
                </div>
                ` : ''}
            `;
            
            ratingsList.appendChild(ratingDiv);
        });
    } else {
        document.getElementById('noRatingsMessage').style.display = 'block';
    }
}

function closeRatingsModal() {
    const modal = document.getElementById('ratingsModal');
    modal.classList.remove('active');
    document.body.classList.remove('modal-open');
}

// Also update the ESC key handler
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReviewsModal();
        closeRatingsModal();
    }
});

// Update click outside handler
document.addEventListener('click', function(e) {
    const reviewsModal = document.getElementById('reviewsModal');
    const ratingsModal = document.getElementById('ratingsModal');
    
    if (e.target === reviewsModal || e.target.classList.contains('vc-modal-overlay')) {
        closeReviewsModal();
    }
    if (e.target === ratingsModal || e.target.classList.contains('vc-modal-overlay')) {
        closeRatingsModal();
    }
});
</script>

<script>
// Rating functionality
let currentRating = 0;

function rateOpportunity(oppId, orgId) {
    document.getElementById('rateOpportunityId').value = oppId;
    document.getElementById('rateOrgId').value = orgId;
    document.getElementById('rateModal').style.display = 'flex';
    
    // Reset stars
    currentRating = 0;
    document.querySelectorAll('#starRating .fa-star').forEach(star => {
        star.className = 'far fa-star';
    });
    document.getElementById('ratingValue').value = 0;
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Star rating interaction
document.addEventListener('DOMContentLoaded', function() {
    const stars = document.querySelectorAll('#starRating .fa-star');
    const ratingLabel = document.querySelector('.vc-rating-label');
    
    stars.forEach(star => {
        star.addEventListener('mouseover', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            highlightStars(rating);
            updateRatingLabel(rating);
        });
        
        star.addEventListener('click', function() {
            currentRating = parseInt(this.getAttribute('data-rating'));
            document.getElementById('ratingValue').value = currentRating;
            highlightStars(currentRating);
            updateRatingLabel(currentRating);
        });
    });
    
    document.getElementById('starRating').addEventListener('mouseleave', function() {
        highlightStars(currentRating);
        updateRatingLabel(currentRating);
    });
});

function highlightStars(rating) {
    const stars = document.querySelectorAll('#starRating .fa-star');
    stars.forEach((star, index) => {
        const starNum = index + 1;
        if (starNum <= rating) {
            star.className = 'fas fa-star';
        } else {
            star.className = 'far fa-star';
        }
    });
}

function updateRatingLabel(rating) {
    const labels = [
        'Click to rate',
        'Poor',
        'Fair',
        'Good',
        'Very Good',
        'Excellent'
    ];
    document.querySelector('.vc-rating-label').textContent = labels[rating];
}

</script>