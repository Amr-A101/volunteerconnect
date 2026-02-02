<?php
    $dob = new DateTime($volunteer['birthdate']);
    $now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $age = $now->diff($dob)->y;

    $gender_code = strtolower($volunteer['gender']);

    $gender_icon = '';

    if ($gender_code === 'm') {
        $gender_icon = '<i class="fa-solid fa-mars vc-gender-mars"></i>';
    } elseif ($gender_code === 'f') {
        $gender_icon = '<i class="fa-solid fa-venus vc-gender-venus"></i>';
    } else {
        $gender_icon = '<i class="fa-solid fa-user vc-gender-neutral"></i>';
    }

    // Get current tab from URL
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

    // Fetch attended opportunities for this volunteer
    $participated_opportunities = [];
    
    if (isset($volunteer['vol_id'])) {
        $part_stmt = $dbc->prepare("
            SELECT o.*, p.status, p.hours_worked, p.participated_at,
                    org.name as org_name,
                    (SELECT AVG(rating) FROM reviews r WHERE r.opportunity_id = o.opportunity_id AND r.reviewee_type = 'volunteer' AND r.reviewee_id = ?) as avg_rating,
                    (SELECT COUNT(*) FROM reviews r WHERE r.opportunity_id = o.opportunity_id AND r.reviewee_type = 'volunteer' AND r.reviewee_id = ?) as rating_count
            FROM participation p
            JOIN opportunities o ON p.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE p.volunteer_id = ?
            AND p.status = 'attended'
            ORDER BY p.participated_at DESC
        ");
        $part_stmt->bind_param("iii", $volunteer['vol_id'], $volunteer['vol_id'], $volunteer['vol_id']);
        $part_stmt->execute();
        $part_result = $part_stmt->get_result();
        
        while ($opp = $part_result->fetch_assoc()) {
            $participated_opportunities[] = $opp;
        }
        $part_stmt->close();
    }

    // Fetch reviews for this volunteer
    $reviews = [];
    $review_stats = [
        'total' => 0,
        'average' => $avg_rating,
        'counts' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]
    ];

    if (isset($volunteer['vol_id'])) {
        // Get all reviews for this volunteer (as reviewee)
        $review_stmt = $dbc->prepare("
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
            WHERE r.reviewee_type = 'volunteer' 
            AND r.reviewee_id = ?
            ORDER BY r.created_at DESC
        ");
        $review_stmt->bind_param("i", $volunteer['vol_id']);
        $review_stmt->execute();
        $review_result = $review_stmt->get_result();
        
        while ($review = $review_result->fetch_assoc()) {
            $reviews[] = $review;
            $review_stats['counts'][$review['rating']]++;
        }
        $review_stmt->close();
        
        $review_stats['total'] = count($reviews);
    }
?>

<link rel="stylesheet" href="/volcon/assets/css/profile_base.css">
<link rel="stylesheet" href="/volcon/assets/css/profile_vol.css">

<!-- profile page - volunteer -->
<div class="vc-pro-page">

    <!-- TOP FLOATING BAR -->
    <div class="vc-pro-topbar">
        <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'dashboard_vol.php') ?>" class="vc-pro-btn-light">← Back</a>
        

        <?php if ($is_self): ?>
            <a href="/volcon/app/update_profile_vol.php" class="vc-pro-btn-primary">Edit Profile</a>
        
        <?php elseif ($is_vol || $is_org): ?>
            <button 
                class="vc-pro-btn-primary"
                onclick="startChatWith(
                    <?= (int)$volunteer['vol_id'] ?>,
                    'vol',
                    '<?= esc($volunteer['first_name'].' '.$volunteer['last_name']) ?>',
                    '<?= esc($volunteer['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png') ?>'
                )">
                <i class="fas fa-comment"></i> Send Message
            </button>

        <?php else: ?>
            <a href="/volcon/index.php" class="vc-pro-btn-light">← Home</a>
        <?php endif; ?>
    </div>

    <!-- HEADER / HERO -->
    <div class="vc-pro-hero">

        <div class="vc-pro-avatar-wrapper">
            <img src="<?= $volunteer['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png'; ?>" class="vc-pro-avatar">
        </div>

        <div class="vc-pro-content">
            <div>
                <h1 class="vc-pro-name"><?= htmlspecialchars($volunteer['full_name']) ?></h1>

                <p class="vc-pro-details">
                    @<?= htmlspecialchars($volunteer['username']); ?>
                    </br>
                    <?= $age ?>
                    <?= $gender_icon ?>
                </p>
            </div>
            <div class="vc-pro-subcontent">
                <p class="vc-pro-location">
                    <i class="fa-solid fa-location-dot"></i>
                    <?= htmlspecialchars($volunteer['city']) ?>, 
                    <?= htmlspecialchars($volunteer['state']) ?>, 
                    <?= htmlspecialchars($volunteer['country']) ?>
                </p>
                
                <!-- Rating Stars -->
                <?php if ($rating_count > 0): ?>
                <div class="vc-vol-rating" onclick="openReviewsModal()" style="cursor: pointer;">
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
                        <span class="vc-rating-score">
                            <?= number_format($avg_rating ?? 0, 1) ?>
                        </span>
                        <i class="fas fa-external-link-alt vc-rating-link"></i>
                    </div>
                    <div class="vc-rating-count">
                        (<?= $rating_count ?> review<?= $rating_count != 1 ? 's' : '' ?>)
                    </div>
                </div>
                <?php else: ?>
                <div class="vc-vol-rating" onclick="openReviewsModal()" style="cursor: pointer;">
                    <div class="vc-rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star vc-star-empty"></i>
                        <?php endfor; ?>
                        <span class="vc-rating-score">No ratings yet</span>
                        <i class="fas fa-external-link-alt vc-rating-link"></i>
                    </div>
                </div>
                <?php endif; ?>

                <span class="vc-availability-pill <?= $availability_class ?>">
                    <?= $availability_icon ?>
                    <?= $availability_label ?>
                </span>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="vc-vol-tabs">
        <a href="?id=<?= $volunteer['vol_id'] ?>&tab=profile" 
           class="vc-vol-tab <?= $current_tab === 'profile' ? 'active' : '' ?>">
            <i class="fas fa-user-circle"></i> Profile Info
        </a>
        <a href="?id=<?= $volunteer['vol_id'] ?>&tab=participation" 
           class="vc-vol-tab <?= $current_tab === 'participation' ? 'active' : '' ?>">
            <i class="fas fa-hands-helping"></i> Participation History
            <span class="vc-tab-badge"><?= count($participated_opportunities ?? []) ?></span>
        </a>
    </div>

    <!-- TAB CONTENT -->
    <div class="vc-vol-tab-content">
        <?php if ($current_tab === 'profile'): ?>
            <!-- PROFILE INFO TAB -->
            
            <!-- STATS SECTION -->
            <div class="vc-pro-stats-card">

                <div class="vc-pro-stat">
                    <div class="num"><?= $skillCount ?></div>
                    <div class="label">Skills</div>
                </div>

                <div class="vc-pro-stat">
                    <div class="num"><?= $interestCount ?></div>
                    <div class="label">Interests</div>
                </div>

            </div>

            <!-- ABOUT CARD -->
            <?php if (!empty($volunteer['bio'])): ?>
            <div class="vc-pro-card">
                <h3>About</h3>
                <p><?= nl2br(htmlspecialchars($volunteer['bio'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- SKILLS -->
            <?php if (!empty($skills)): ?>
            <div class="vc-pro-card">
                <h3>Skills</h3>
                <div class="vc-pro-pill-grid">
                    <?php foreach ($skills as $s): ?>
                        <span class="vc-pro-pill"><?= htmlspecialchars($s['skill_name']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- INTERESTS -->
            <?php if (!empty($interests)): ?>
            <div class="vc-pro-card">
                <h3>Interests</h3>
                <div class="vc-pro-pill-grid">
                    <?php foreach ($interests as $intr): ?>
                        <span class="vc-pro-pill"><?= htmlspecialchars($intr['interest_name']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- EMERGENCY CONTACTS (ACCORDION) -->
            <?php if ($is_self): ?>
            <div class="vc-pro-card vc-pro-accordion">

                <button class="vc-pro-acc-btn">Emergency Contacts (Private)</button>

                <div class="vc-pro-acc-content">
                    <?php if (!empty($volunteer['emergency_contacts'])): ?>
                        <ul>
                            <?php foreach ($volunteer['emergency_contacts'] as $name => $number): ?>
                                <li><b><?= htmlspecialchars($name); ?>:</b> <?= htmlspecialchars($number); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No emergency contacts added.</p>
                    <?php endif; ?>
                </div>

            </div>
            <?php endif; ?>

        <?php elseif ($current_tab === 'participation'): ?>
            <!-- PARTICIPATION HISTORY TAB -->
            
            <?php if (!empty($participated_opportunities)): ?>
            <div class="vc-part-section">
                <h3 class="vc-section-title">
                    <i class="fas fa-history"></i> Attended Opportunities
                    <span class="vc-section-count"><?= count($participated_opportunities) ?></span>
                </h3>
                
                <div class="vc-part-stats">
                    <div class="vc-part-stat">
                        <div class="vc-part-stat-value">
                            <?= array_sum(array_column($participated_opportunities, 'hours_worked')) ?>
                        </div>
                        <div class="vc-part-stat-label">Total Hours</div>
                    </div>
                    <div class="vc-part-stat">
                        <div class="vc-part-stat-value">
                            <?= count($participated_opportunities) ?>
                        </div>
                        <div class="vc-part-stat-label">Events Attended</div>
                    </div>
                    <div class="vc-part-stat">
                        <div class="vc-part-stat-value">
                            <?= count(array_unique(array_column($participated_opportunities, 'org_id'))) ?>
                        </div>
                        <div class="vc-part-stat-label">Organizations</div>
                    </div>
                </div>
                
                <div class="vc-part-grid">
                    <?php foreach ($participated_opportunities as $opp): ?>
                    <div class="vc-part-card">
                        <div class="vc-part-header">
                            <div>
                                <h4 class="vc-part-title">
                                    <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>">
                                        <b><?= htmlspecialchars($opp['title']) ?></b>
                                    </a>
                                </h4>
                                <p class="vc-part-org">
                                    <i class="fas fa-building"></i>
                                    <?= htmlspecialchars($opp['org_name']) ?>
                                </p>
                            </div>
                            <span class="vc-badge vc-status-attended">Attended</span>
                        </div>
                        
                        <div class="vc-part-meta">
                            <?php if ($opp['participated_at']): ?>
                            <span><i class="fas fa-calendar-check"></i> 
                                <?= date('M d, Y', strtotime($opp['participated_at'])) ?>
                            </span>
                            <?php endif; ?>
                            
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
                        
                        <?php if ($opp['hours_worked']): ?>
                        <div class="vc-part-hours">
                            <span class="vc-hours-badge">
                                <i class="fas fa-clock"></i> <?= $opp['hours_worked'] ?> hours
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Rating from organization -->
                        <?php if ($opp['avg_rating'] > 0): ?>
                            <div class="vc-part-rating">
                                <div class="vc-rating-label">
                                    Organization rated 
                                    <?php if ($is_self): ?>
                                        you:
                                    <?php elseif ($volunteer['gender'] === 'm'): ?>
                                        him:
                                    <?php else: ?>
                                        her:
                                    <?php endif; ?>
                                </div>
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
                                    <span class="vc-rating-score">
                                        <?= number_format($opp['avg_rating'] ?? 0, 1) ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="vc-part-rating">
                                <div class="vc-rating-label">No rating yet</div>
                                <div class="vc-rating-stars vc-rating-small">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="far fa-star vc-star-empty"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="vc-part-footer">
                            <div class="vc-part-actions">
                                <a href="view_opportunity.php?id=<?= $opp['opportunity_id'] ?>" 
                                   class="vc-btn vc-btn-secondary vc-btn-sm">
                                    View Opportunity
                                </a>
                                <?php if ($is_self): ?>
                                <a href="profile_org_view.php?id=<?= $opp['org_id'] ?>" 
                                   class="vc-btn vc-btn-primary vc-btn-sm">
                                    View Organization
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
                <div class="vc-empty-icon"><i class="fas fa-frown"></i></div>
                <h3>No Participation History</h3>
                <p>This volunteer hasn't attended any opportunities yet.</p>
                <?php if ($is_self): ?>
                <a href="browse_opportunities.php" class="vc-btn vc-btn-primary mt-3">
                    <i class="fas fa-search"></i> Find Opportunities
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</div>

<!-- Reviews Modal -->
<div id="reviewsModal" class="vc-modal">
    <div class="vc-modal-overlay" onclick="closeReviewsModal()"></div>
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-star"></i> 
                Reviews for <?= htmlspecialchars($volunteer['first_name'] ?? 'User') ?>
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
                                 style="width: <?= $review_stats['total'] > 0 ? ($review_stats['counts'][$i] / $review_stats['total'] * 100) : 0 ?>%">
                            </div>
                        </div>
                        <span class="vc-rating-count"><?= $review_stats['counts'][$i] ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="vc-reviews-summary">
                <div class="vc-total-reviews">
                    <div class="vc-total-number"><?= $review_stats['total'] ?></div>
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
            <?php if (!empty($reviews)): ?>
                <?php foreach ($reviews as $review): ?>
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
document.querySelectorAll('.vc-pro-acc-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.classList.toggle('active');
        let content = btn.nextElementSibling;
        content.style.display = content.style.display === "block" ? "none" : "block";
    });
});
</script>

<script>
// Modal Functions
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