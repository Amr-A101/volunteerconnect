<?php
// views/vol/dashboard_vol_view.php

declare(strict_types=1);

require_once __DIR__ . '/../../core/RecommendationService.php';

// Ensure arrays exist to avoid warnings if controller omitted them
$volunteer['skills']     = $volunteer['skills']     ?? [];
$volunteer['interests']  = $volunteer['interests']  ?? [];
$volunteer['profile_picture'] = $volunteer['profile_picture'] ?? null;
$user = $user ?? ['email' => ''];


if (!isset($pendingApps) || !($pendingApps instanceof mysqli_result)) {
    $pendingQuery = "
        SELECT p.participation_id, p.participated_at, o.title, o.opportunity_id, org.name as org_name
        FROM participation p
        JOIN opportunities o ON p.opportunity_id = o.opportunity_id
        JOIN organizations org ON o.org_id = org.org_id
        WHERE p.volunteer_id = ?
          AND p.status = 'pending'
        ORDER BY p.participated_at DESC
    ";
    $stmtPending = $dbc->prepare($pendingQuery);
    $stmtPending->bind_param("i", $volunteer['vol_id']);
    $stmtPending->execute();
    $pendingApps = $stmtPending->get_result();
    $stmtPending->close();
}

if (!isset($upcomingAssignments) || !($upcomingAssignments instanceof mysqli_result)) {
    $upcomingQuery = "
        SELECT p.participation_id, o.title, o.opportunity_id, o.start_date, o.start_time,
               o.end_time, o.location_name, o.city, org.name as org_name
        FROM participation p
        JOIN opportunities o ON p.opportunity_id = o.opportunity_id
        JOIN organizations org ON o.org_id = org.org_id
        WHERE p.volunteer_id = ?
          AND p.status = 'attended'
          AND o.start_date >= CURDATE()
        ORDER BY o.start_date ASC, o.start_time ASC
        LIMIT 5
    ";
    $stmtUpcoming = $dbc->prepare($upcomingQuery);
    $stmtUpcoming->bind_param("i", $volunteer['vol_id']);
    $stmtUpcoming->execute();
    $upcomingAssignments = $stmtUpcoming->get_result();
    $stmtUpcoming->close();
}

if (!isset($completedEngagements) || !($completedEngagements instanceof mysqli_result)) {
    $completedQuery = "
        SELECT p.participation_id, o.title, o.opportunity_id, o.end_date, p.hours_worked,
               org.name as org_name
        FROM participation p
        JOIN opportunities o ON p.opportunity_id = o.opportunity_id
        JOIN organizations org ON o.org_id = org.org_id
        WHERE p.volunteer_id = ?
          AND p.status = 'attended'
          AND o.end_date < CURDATE()
        ORDER BY o.end_date DESC
        LIMIT 10
    ";
    $stmtCompleted = $dbc->prepare($completedQuery);
    $stmtCompleted->bind_param("i", $volunteer['vol_id']);
    $stmtCompleted->execute();
    $completedEngagements = $stmtCompleted->get_result();
    $stmtCompleted->close();
}

if (!isset($feedbackResults) || !($feedbackResults instanceof mysqli_result)) {
    $feedbackQuery = "
        SELECT r.review_id, r.rating, r.review_text, r.created_at,
               o.title as opportunity_title, o.opportunity_id,
               org.name as org_name
        FROM reviews r
        JOIN opportunities o ON r.opportunity_id = o.opportunity_id
        JOIN organizations org ON o.org_id = org.org_id
        WHERE r.reviewee_type = 'volunteer'
          AND r.reviewee_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ";
    $stmtFeedback = $dbc->prepare($feedbackQuery);
    $stmtFeedback->bind_param("i", $volunteer['vol_id']);
    $stmtFeedback->execute();
    $feedbackResults = $stmtFeedback->get_result();
    $stmtFeedback->close();
}

// Average rating
if (!isset($avgRating) || !isset($totalReviews)) {
    $avgRatingQuery = "
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM reviews
        WHERE reviewee_type = 'volunteer' AND reviewee_id = ?
    ";
    $stmtAvg = $dbc->prepare($avgRatingQuery);
    $stmtAvg->bind_param("i", $volunteer['vol_id']);
    $stmtAvg->execute();
    $ratingData = $stmtAvg->get_result()->fetch_assoc() ?: ['avg_rating' => 0, 'total_reviews' => 0];
    $stmtAvg->close();

    $avgRating = $ratingData['avg_rating'] ? round((float)$ratingData['avg_rating'], 1) : 0;
    $totalReviews = (int)$ratingData['total_reviews'];
}

$recommendedOpps = $recommendedOpportunities ?? [];
$isProfileReady = !empty($volunteer['skills']) && !empty($volunteer['interests']);


// Counts for overview cards
$pendingCount = $pendingApps->num_rows ?? 0;
$upcomingCount = $upcomingAssignments->num_rows ?? 0;
$completedCount = $completedEngagements->num_rows ?? 0;

$allowedTabs = ['overview', 'pending', 'upcoming', 'completed', 'saved', 'feedback', 'recommended'];

$activeTab = $_GET['tab'] ?? 'overview';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}
?>



<div class="vc-dashboard vc-dashboard-vol">

    <!-- LEFT SIDEBAR -->
    <aside class="vc-dashboard-sidebar">

        <!-- PROFILE CARD -->
        <div class="vc-profile-card">
            <div class="vc-profile-header">
                <img
                    src="<?= htmlspecialchars($volunteer['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png') ?>"
                    alt="Profile Picture"
                    class="vc-avatar"
                >
                <h3 class="vc-profile-name"><?= htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?></h3>

                <?php if ($totalReviews > 0): ?>
                <div class="vc-rating-display" aria-label="Average rating: <?= number_format($avgRating, 1) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <?php if ($i <= floor($avgRating)): ?>
                            <!-- Full star -->
                            <i class="fas fa-star filled"></i>
                        <?php elseif ($i == ceil($avgRating) && $avgRating - floor($avgRating) >= 0.5): ?>
                            <!-- Half star -->
                            <i class="fas fa-star-half-alt filled"></i>
                        <?php else: ?>
                            <!-- Empty star -->
                            <i class="far fa-star"></i>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <span class="vc-rating-value"><?= number_format($avgRating, 1) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="vc-profile-details">
                <div class="vc-detail-item">
                    <i class="fas fa-envelope"></i>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="vc-detail-item">
                    <i class="fas fa-phone"></i>
                    <span><?= htmlspecialchars($volunteer['phone_no'] ?? '') ?></span>
                </div>
            </div>

            <a href="profile_vol.php" class="vc-btn-profile">
                <i class="fas fa-user"></i> View Profile
            </a>
        </div>

        <!-- SKILLS SECTION -->
        <?php if (!empty($volunteer['skills'])): ?>
        <div class="vc-sidebar-card">
            <h4 class="vc-sidebar-title">
                <i class="fas fa-tools"></i> Skills
            </h4>
            <div class="vc-tags-list">
                <?php foreach ($volunteer['skills'] as $skill): ?>
                    <span class="vc-tag vc-tag-skill"><?= htmlspecialchars($skill) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- INTERESTS SECTION -->
        <?php if (!empty($volunteer['interests'])): ?>
        <div class="vc-sidebar-card">
            <h4 class="vc-sidebar-title">
                <i class="fas fa-heart"></i> Interests
            </h4>
            <div class="vc-tags-list">
                <?php foreach ($volunteer['interests'] as $interest): ?>
                    <span class="vc-tag vc-tag-interest"><?= htmlspecialchars($interest) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="vc-dashboard-main">

        <!-- ALERT -->
        <?php if (!empty($deletedCount) && $deletedCount > 0): ?>
            <div class="vc-alert vc-alert-warning" data-timeout="15">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Notice:</strong> <?= (int)$deletedCount ?> of your applied opportunities were deleted.
                    <a href="my_applications.php">View Details</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD TABS -->
        <div class="vc-tabs-container">
            <div class="vc-tabs" role="tablist" aria-label="Dashboard Tabs">

                <button class="vc-tab <?= $activeTab === 'overview' ? 'active' : '' ?>"
                    data-tab="overview"
                    data-tab-link="?tab=overview"
                    role="tab"
                    aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>">
                    <i class="fas fa-th-large"></i><span>Overview</span>
                </button>

                <button class="vc-tab <?= $activeTab === 'recommended' ? 'active' : '' ?>"
                    data-tab="recommended"
                    data-tab-link="?tab=recommended"
                    role="tab"
                    aria-selected="<?= $activeTab === 'recommended' ? 'true' : 'false' ?>">
                    <i class="fas fa-lightbulb"></i><span>For You</span>
                </button>

                <button class="vc-tab <?= $activeTab === 'pending' ? 'active' : '' ?>"
                    data-tab="pending"
                    data-tab-link="?tab=pending"
                    role="tab"
                    aria-selected="<?= $activeTab === 'pending' ? 'true' : 'false' ?>">
                    <i class="fas fa-clock"></i><span>Pending</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="vc-tab-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </button>

                <button class="vc-tab <?= $activeTab === 'upcoming' ? 'active' : '' ?>"
                    data-tab="upcoming"
                    data-tab-link="?tab=upcoming"
                    role="tab"
                    aria-selected="<?= $activeTab === 'upcoming' ? 'true' : 'false' ?>">
                    <i class="fas fa-calendar-check"></i><span>Upcoming</span>
                </button>

                <button class="vc-tab <?= $activeTab === 'completed' ? 'active' : '' ?>"
                    data-tab="completed"
                    data-tab-link="?tab=completed"
                    role="tab"
                    aria-selected="<?= $activeTab === 'completed' ? 'true' : 'false' ?>">
                    <i class="fas fa-check-circle"></i><span>Completed</span>
                </button>

                <button class="vc-tab <?= $activeTab === 'saved' ? 'active' : '' ?>"
                    data-tab="saved"
                    data-tab-link="?tab=saved"
                    role="tab"
                    aria-selected="<?= $activeTab === 'saved' ? 'true' : 'false' ?>">
                    <i class="fas fa-bookmark"></i>
                    <span>Saved</span>
                    <?php if (!empty($savedCount)): ?>
                        <span class="vc-tab-badge"><?= $savedCount ?></span>
                    <?php endif; ?>
                </button>


                <button class="vc-tab <?= $activeTab === 'feedback' ? 'active' : '' ?>"
                    data-tab="feedback"
                    data-tab-link="?tab=feedback"
                    role="tab"
                    aria-selected="<?= $activeTab === 'feedback' ? 'true' : 'false' ?>">
                    <i class="fas fa-star"></i><span>Feedback</span>
                </button>

            </div>
        </div>


        <!-- TAB: OVERVIEW -->
        <div class="vc-tab-panel <?= $activeTab === 'overview' ? 'active' : '' ?>"
            data-panel="overview"
            role="tabpanel">
            <div class="vc-stats-grid">
                <div class="vc-stat-card vc-stat-purple">
                    <div class="vc-stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="vc-stat-info">
                        <h3><?= $pendingCount ?></h3><p>Pending Applications</p>
                    </div>
                </div>
                <div class="vc-stat-card vc-stat-blue">
                    <div class="vc-stat-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="vc-stat-info">
                        <h3><?= $upcomingCount ?></h3><p>Upcoming Events</p>
                    </div>
                </div>
                <div class="vc-stat-card vc-stat-green">
                    <div class="vc-stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="vc-stat-info">
                        <h3><?= $completedCount ?></h3><p>Completed</p>
                    </div>
                </div>
                <div class="vc-stat-card vc-stat-orange">
                    <div class="vc-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="vc-stat-info">
                        <h3><?= number_format($avgRating, 1) ?></h3><p>Average Rating</p>
                    </div>
                </div>
            </div>

            <div class="vc-quick-actions">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="vc-actions-grid">
                    <a href="browse_opportunities.php" class="vc-action-card">
                        <i class="fas fa-search"></i><span>Browse Opportunities</span>
                    </a>
                    <a href="my_applications.php" class="vc-action-card">
                        <i class="fas fa-list"></i><span>My Applications</span>
                    </a>
                    <a href="update_profile_vol.php" class="vc-action-card">
                        <i class="fas fa-user-edit"></i><span>Edit Profile</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- TAB: PENDING APPLICATIONS -->
        <div class="vc-tab-panel <?= $activeTab === 'pending' ? 'active' : '' ?>"
            data-panel="pending"
            role="tabpanel">
            <h2 class="vc-section-title">Pending Applications</h2>
            <?php if ($pendingApps && $pendingApps->num_rows > 0): ?>
                <div class="vc-items-list">
                    <?php while ($app = $pendingApps->fetch_assoc()): ?>
                        <div class="vc-item-card">
                            <div class="vc-item-icon vc-icon-warning"><i class="fas fa-hourglass-half"></i></div>
                            <div class="vc-item-content">
                                <h4><a href="opportunity_detail.php?id=<?= (int)$app['opportunity_id'] ?>">
                                    <?= htmlspecialchars($app['title']) ?>
                                </a></h4>
                                <p class="vc-item-meta"><i class="fas fa-building"></i> <?= htmlspecialchars($app['org_name']) ?></p>
                                <p class="vc-item-meta"><i class="fas fa-calendar"></i> Applied on <?= date('M d, Y', strtotime($app['participated_at'])) ?></p>
                            </div>
                            <span class="vc-badge vc-badge-warning">Pending</span>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state"><i class="fas fa-inbox"></i><p>No pending applications</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: UPCOMING ASSIGNMENTS -->
        <div class="vc-tab-panel <?= $activeTab === 'upcoming' ? 'active' : '' ?>"
            data-panel="upcoming"
            role="tabpanel">
            <h2 class="vc-section-title">Upcoming Assignments</h2>
            <?php if ($upcomingAssignments && $upcomingAssignments->num_rows > 0): ?>
                <div class="vc-items-list">
                    <?php while ($assignment = $upcomingAssignments->fetch_assoc()): ?>
                        <div class="vc-item-card">
                            <div class="vc-item-icon vc-icon-blue"><i class="fas fa-calendar-alt"></i></div>
                            <div class="vc-item-content">
                                <h4><a href="opportunity_detail.php?id=<?= (int)$assignment['opportunity_id'] ?>">
                                    <?= htmlspecialchars($assignment['title']) ?>
                                </a></h4>
                                <p class="vc-item-meta"><i class="fas fa-building"></i> <?= htmlspecialchars($assignment['org_name']) ?></p>
                                <p class="vc-item-meta">
                                    <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($assignment['start_date'])) ?>
                                    <?php if (!empty($assignment['start_time'])): ?>
                                        • <i class="fas fa-clock"></i> <?= date('g:i A', strtotime($assignment['start_time'])) ?>
                                        <?php if (!empty($assignment['end_time'])): ?> - <?= date('g:i A', strtotime($assignment['end_time'])) ?><?php endif; ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($assignment['location_name'])): ?>
                                <p class="vc-item-meta"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($assignment['location_name']) ?>, <?= htmlspecialchars($assignment['city']) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="vc-badge vc-badge-success">Confirmed</span>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state"><i class="fas fa-calendar-times"></i><p>No upcoming assignments</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: COMPLETED ENGAGEMENTS -->
        <div class="vc-tab-panel <?= $activeTab === 'completed' ? 'active' : '' ?>"
            data-panel="completed"
            role="tabpanel">
            <h2 class="vc-section-title">Completed Engagements</h2>
            <?php if ($completedEngagements && $completedEngagements->num_rows > 0): ?>
                <div class="vc-items-list">
                    <?php while ($completed = $completedEngagements->fetch_assoc()): ?>
                        <div class="vc-item-card">
                            <div class="vc-item-icon vc-icon-green"><i class="fas fa-check-circle"></i></div>
                            <div class="vc-item-content">
                                <h4><a href="opportunity_detail.php?id=<?= (int)$completed['opportunity_id'] ?>">
                                    <?= htmlspecialchars($completed['title']) ?>
                                </a></h4>
                                <p class="vc-item-meta"><i class="fas fa-building"></i> <?= htmlspecialchars($completed['org_name']) ?></p>
                                <p class="vc-item-meta"><i class="fas fa-calendar"></i> Completed on <?= date('M d, Y', strtotime($completed['end_date'])) ?></p>
                                <?php if (!empty($completed['hours_worked'])): ?><p class="vc-item-meta"><i class="fas fa-clock"></i> <?= (int)$completed['hours_worked'] ?> hours volunteered</p><?php endif; ?>
                            </div>
                            <span class="vc-badge vc-badge-info">Completed</span>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state"><i class="fas fa-clipboard-list"></i><p>No completed engagements yet</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: SAVED OPPORTUNITIES -->
        <div class="vc-tab-panel <?= $activeTab === 'saved' ? 'active' : '' ?>"
            data-panel="saved"
            role="tabpanel">

            <h2 class="vc-section-title">Saved Opportunities</h2>
            <p class="vc-section-subtitle">Opportunities you bookmarked for later</p>

            <?php if (!empty($savedOpportunities)): ?>
                <div class="vc-items-list">
                    <?php foreach ($savedOpportunities as $opp): ?>
                        <div class="vc-item-card">
                            <div class="vc-item-icon vc-icon-purple">
                                <i class="fas fa-bookmark"></i>
                            </div>

                            <div class="vc-item-content">
                                <h4>
                                    <a href="view_opportunity.php?id=<?= (int)$opp['opportunity_id'] ?>">
                                        <?= htmlspecialchars($opp['title']) ?>
                                    </a>
                                </h4>

                                <p class="vc-item-meta">
                                    <i class="fas fa-building"></i>
                                    <?= htmlspecialchars($opp['org_name']) ?>
                                </p>

                                <p class="vc-item-meta">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($opp['city']) ?>
                                    <?= !empty($opp['state']) ? ', ' . htmlspecialchars($opp['state']) : '' ?>
                                </p>

                                <?php if (!empty($opp['brief_summary'])): ?>
                                    <p class="vc-item-desc">
                                        <?= htmlspecialchars($opp['brief_summary']) ?>
                                    </p>
                                <?php endif; ?>

                                <p class="vc-item-meta">
                                    <i class="fas fa-clock"></i>
                                    Saved on <?= date('M d, Y', strtotime($opp['saved_at'])) ?>
                                </p>
                            </div>

                            <a href="view_opportunity.php?id=<?= (int)$opp['opportunity_id'] ?>"
                            class="vc-btn-secondary">
                                Read More
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state">
                    <i class="fas fa-bookmark"></i>
                    <p>You haven’t saved any opportunities yet.</p>
                    <a href="browse_opportunities.php" class="vc-btn-primary">
                        Browse Opportunities
                    </a>
                </div>
            <?php endif; ?>
        </div>


        <!-- TAB: FEEDBACK & RATINGS -->
        <div class="vc-tab-panel <?= $activeTab === 'feedback' ? 'active' : '' ?>"
            data-panel="feedback"
            role="tabpanel">
            <h2 class="vc-section-title">Feedback & Ratings</h2>

            <?php if ($totalReviews > 0): ?>
                <div class="vc-rating-summary-card">
                    <div class="vc-rating-big">
                        <span class="vc-rating-number"><?= number_format($avgRating, 1) ?></span>
                        <div class="vc-stars-display">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?php if ($i <= floor($avgRating)): ?>
                                    <!-- Full star -->
                                    <i class="fas fa-star filled"></i>
                                <?php elseif ($i == ceil($avgRating) && $avgRating - floor($avgRating) >= 0.5): ?>
                                    <!-- Half star -->
                                    <i class="fas fa-star-half-alt filled"></i>
                                <?php else: ?>
                                    <!-- Empty star -->
                                    <i class="far fa-star"></i>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <p class="vc-rating-count"><?= $totalReviews ?> review<?= $totalReviews != 1 ? 's' : '' ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($feedbackResults && $feedbackResults->num_rows > 0): ?>
                <div class="vc-reviews-list">
                    <?php while ($review = $feedbackResults->fetch_assoc()): ?>
                        <div class="vc-review-card">
                            <div class="vc-review-header">
                                <div class="vc-review-info">
                                    <h4><?= htmlspecialchars($review['org_name']) ?></h4>
                                    <p class="vc-review-opp">
                                        <a href="opportunity_detail.php?id=<?= (int)$review['opportunity_id'] ?>">
                                            <?= htmlspecialchars($review['opportunity_title']) ?>
                                        </a>
                                    </p>
                                </div>
                                <div class="vc-review-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= (int)$review['rating']): ?>
                                            <!-- Full star -->
                                            <i class="fas fa-star filled"></i>
                                        <?php elseif ($i == ceil((float)$review['rating']) && (float)$review['rating'] - floor((float)$review['rating']) >= 0.5): ?>
                                            <!-- Half star -->
                                            <i class="fas fa-star-half-alt filled"></i>
                                        <?php else: ?>
                                            <!-- Empty star -->
                                            <i class="far fa-star"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php if (!empty($review['review_text'])): ?><p class="vc-review-text"><?= htmlspecialchars($review['review_text']) ?></p><?php endif; ?>
                            <p class="vc-review-date"><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($review['created_at'])) ?></p>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state"><i class="fas fa-star-half-alt"></i><p>No feedback received yet</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: RECOMMENDED OPPORTUNITIES -->
        <div class="vc-tab-panel <?= $activeTab === 'recommended' ? 'active' : '' ?>"
            data-panel="recommended"
            role="tabpanel">
            <h2 class="vc-section-title">Recommended for You</h2>
            <p class="vc-section-subtitle">Based on your interests, skills, and volunteer history</p>

            <div class="vc-recommend-filters">
                <span class="vc-filter-label">Prioritize:</span>

                <?php
                $priority = $_GET['priority'] ?? 'overall';
                $filters = [
                    'overall'  => 'Overall',
                    // 'skill'    => 'Skills',
                    // 'interest' => 'Interests',
                    // 'location' => 'Location'
                ];
                ?>

                <?php foreach ($filters as $key => $label): ?>
                    <a href="?tab=recommended&priority=<?= $key ?>"
                    class="vc-filter-btn <?= $priority === $key ? 'active' : '' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>


            <?php if (!empty($recommendedOpps)): ?>
                <div class="vc-opportunities-grid">
                    <?php foreach ($recommendedOpps as $opp): ?>
                        <?php
                        $match = $opp['match'] ?? null;
                        $matchPercent = $match ? round($match['final'] * 100) : null;
                        ?>

                        <div class="vc-opp-card">
                            <?php if (!empty($opp['image_url'])): ?>
                            <div class="vc-opp-image" style="background-image: url('<?= htmlspecialchars($opp['image_url']) ?>');"></div>
                            <?php else: ?>
                            <div class="vc-opp-image vc-opp-placeholder"><i class="fas fa-hands-helping"></i></div>
                            <?php endif; ?>

                            <div class="vc-opp-body">
                                <h4><?= htmlspecialchars($opp['title']) ?></h4>
                                <?php if ($matchPercent !== null): ?>
                                <div class="vc-match-badge">
                                    <?= $matchPercent ?>% Match
                                </div>
                                <?php endif; ?>

                                <p class="vc-opp-org"><a href="profile_org.php?id=<?= (int)$opp['org_id'] ?>"><?= htmlspecialchars($opp['org_name']) ?></a></p>
                                <?php if (!empty($opp['brief_summary'])): ?>
                                    <p class="vc-opp-desc"><?= htmlspecialchars($opp['brief_summary']) ?></p>
                                <?php endif; ?>

                                <?php if ($match): ?>
                                <div class="vc-match-breakdown">
                                    <div class="vc-match-row">
                                        <span>Interest</span>
                                        <progress value="<?= $match['interest'] * 100 ?>" max="100"></progress>
                                        <span><?= round($match['interest'] * 100) ?>%</span>
                                    </div>
                                    <div class="vc-match-row">
                                        <span>Skills</span>
                                        <progress value="<?= $match['skill'] * 100 ?>" max="100"></progress>
                                        <span><?= round($match['skill'] * 100) ?>%</span>
                                    </div>
                                    <div class="vc-match-row">
                                        <span>Location</span>
                                        <progress value="<?= $match['location'] * 100 ?>" max="100"></progress>
                                        <span><?= round($match['location'] * 100) ?>%</span>
                                    </div>
                                </div>
                                <?php endif; ?>


                                <div class="vc-opp-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($opp['city'] ?? '') ?><?= !empty($opp['state']) ? ', ' . htmlspecialchars($opp['state']) : '' ?></span>
                                    <?php if (!empty($opp['start_date'])): ?><span><i class="fas fa-calendar"></i> <?= date('M d', strtotime($opp['start_date'])) ?></span><?php endif; ?>
                                </div>
                            </div>

                            <div class="vc-opp-footer">
                                <span class="vc-opp-slots"><i class="fas fa-users"></i> <?= (int)($opp['remaining_slots'] ?? 0) ?> slots</span>
                                <a href="view_opportunity.php?id=<?= (int)$opp['opportunity_id'] ?>" class="vc-btn-apply">Read More</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="vc-empty-state">
                    <?php if (!$isProfileReady): ?>
                        <i class="fas fa-lightbulb"></i>
                        <p>Complete your skills and interests to get personalized recommendations!</p>
                        <a href="profile_vol.php" class="vc-btn-primary">Update Profile</a>
                    <?php else: ?>
                        <i class="fas fa-info-circle"></i>
                        <p>No recommended opportunities available right now. Try browsing open opportunities or check back later.</p>
                        <a href="browse_opportunities.php" class="vc-btn-primary">Browse Opportunities</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

</div>

<link rel="stylesheet" href="/volcon/assets/css/dashboard_vol_prem.css">

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.vc-tab');
    const panels = document.querySelectorAll('.vc-tab-panel');

    function activateTab(tab, pushState = true) {
        const target = tab.dataset.tab;

        tabs.forEach(t => {
            t.classList.toggle('active', t === tab);
            t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        });

        panels.forEach(p => {
            p.classList.toggle('active', p.dataset.panel === target);
        });

        if (pushState && tab.dataset.tabLink) {
            const url = new URL(window.location);
            url.searchParams.set('tab', target);
            window.history.pushState({}, '', url);
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            activateTab(this);
        });
    });

    // Handle browser back/forward
    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        const tabName = params.get('tab') || 'overview';
        const tab = document.querySelector(`.vc-tab[data-tab="${tabName}"]`);
        if (tab) activateTab(tab, false);
    });
});
</script>

