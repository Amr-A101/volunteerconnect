<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';

require_role('vol');

$user = current_user();
$volunteer_id = (int)$user['user_id'];

if ($user['role'] !== 'vol') {
    die("Forbidden");
}

// Get current tab from query parameter
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'attendance';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Initialize stats
$stats = [
    'total' => 0,
    'attended' => 0,
    'pending' => 0,
    'absent' => 0,
    'incomplete' => 0
];

// Get volunteer's participation history
$participation_history = [];
$filtered_history = [];

// Base query for participation history
$query = "
    SELECT 
        p.*,
        o.opportunity_id,
        o.title as opportunity_title,
        o.start_date,
        o.end_date,
        o.start_time,
        o.end_time,
        o.city as opp_city,
        o.state as opp_state,
        o.status as opp_status,
        org.org_id,
        org.name as org_name,
        org.profile_picture as org_avatar,
        r.review_id as org_review_id,
        r.rating as org_rating,
        r.review_text as org_review,
        rv.review_id as vol_review_id,
        rv.rating as vol_rating,
        rv.review_text as vol_review
    FROM participation p
    JOIN opportunities o ON p.opportunity_id = o.opportunity_id
    JOIN organizations org ON o.org_id = org.org_id
    LEFT JOIN reviews r ON r.opportunity_id = o.opportunity_id 
        AND r.reviewee_type = 'volunteer' 
        AND r.reviewee_id = p.volunteer_id
        AND r.reviewer_type = 'organization'
    LEFT JOIN reviews rv ON rv.opportunity_id = o.opportunity_id 
        AND rv.reviewee_type = 'organization' 
        AND rv.reviewee_id = o.org_id
        AND rv.reviewer_type = 'volunteer'
        AND rv.reviewer_id = p.volunteer_id
    WHERE p.volunteer_id = ?
    ORDER BY p.participated_at DESC, o.start_date DESC
";

$stmt = $dbc->prepare($query);
$stmt->bind_param("i", $volunteer_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Format dates and times
    $row['formatted_date'] = date('M d, Y', strtotime($row['participated_at']));
    $row['formatted_start'] = $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : 'N/A';
    $row['formatted_end'] = $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : 'N/A';
    
    if ($row['start_time'] && $row['end_time']) {
        $row['time_range'] = date('g:i A', strtotime($row['start_time'])) . ' - ' . 
                           date('g:i A', strtotime($row['end_time']));
    } else {
        $row['time_range'] = '';
    }
    
    // Determine if opportunity is completed
    $row['is_completed'] = ($row['opp_status'] === 'completed');
    $row['is_ongoing'] = ($row['opp_status'] === 'ongoing');
    
    // Determine if volunteer can rate this opportunity
    $row['can_rate_org'] = ($row['status'] === 'attended' || $row['status'] === 'incomplete') && !$row['vol_review_id'] && $row['is_completed'];
    
    $participation_history[] = $row;
    
    // Update stats
    $stats['total']++;
    $stats[$row['status']]++;
}

$stmt->close();

// Filter history based on selected status
if ($filter_status === 'all') {
    $filtered_history = $participation_history;
} else {
    $filtered_history = array_filter($participation_history, function($item) use ($filter_status) {
        return $item['status'] === $filter_status;
    });
}

// Handle form submissions for rating organizations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'rate_organization':
            $opportunity_id = (int)$_POST['opportunity_id'];
            $org_id = (int)$_POST['org_id'];
            $rating = (int)$_POST['rating'];
            $review_text = trim($_POST['review_text'] ?? '');
            
            // Validate rating
            if ($rating < 1 || $rating > 5) {
                $_SESSION['error'] = 'Rating must be between 1 and 5 stars';
                header("Location: my_participation.php?tab=attendance");
                exit();
            }
            
            // Check if volunteer attended this opportunity
            $stmt = $dbc->prepare("
                SELECT status FROM participation 
                WHERE volunteer_id = ? AND opportunity_id = ?
                AND status IN ('attended', 'incomplete')
            ");
            $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
            $stmt->execute();
            $attended = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$attended) {
                $_SESSION['error'] = 'You can only rate opportunities you have attended';
                header("Location: my_participation.php?tab=attendance");
                exit();
            }
            
            // Check if already reviewed
            $stmt = $dbc->prepare("
                SELECT review_id FROM reviews 
                WHERE reviewer_type = 'volunteer' 
                AND reviewer_id = ? 
                AND opportunity_id = ?
                AND reviewee_type = 'organization'
                AND reviewee_id = ?
            ");
            $stmt->bind_param("iii", $volunteer_id, $opportunity_id, $org_id);
            $stmt->execute();
            $already_reviewed = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($already_reviewed) {
                $_SESSION['error'] = 'You have already reviewed this organization for this opportunity';
                header("Location: my_participation.php?tab=attendance");
                exit();
            }
            
            // Insert review
            $stmt = $dbc->prepare("
                INSERT INTO reviews 
                (reviewer_type, reviewer_id, opportunity_id, reviewee_type, reviewee_id, rating, review_text)
                VALUES ('volunteer', ?, ?, 'organization', ?, ?, ?)
            ");
            $stmt->bind_param("iiiss", $volunteer_id, $opportunity_id, $org_id, $rating, $review_text);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Thank you for your review!';
            } else {
                $_SESSION['error'] = 'Failed to submit review: ' . $stmt->error;
            }
            $stmt->close();
            
            header("Location: my_participation.php?tab=attendance");
            exit();
            break;
            
        case 'update_review':
            $review_id = (int)$_POST['review_id'];
            $rating = (int)$_POST['rating'];
            $review_text = trim($_POST['review_text'] ?? '');
            
            // Validate rating
            if ($rating < 1 || $rating > 5) {
                $_SESSION['error'] = 'Rating must be between 1 and 5 stars';
                header("Location: my_participation.php?tab=attendance");
                exit();
            }
            
            // Update review
            $stmt = $dbc->prepare("
                UPDATE reviews 
                SET rating = ?, review_text = ?, created_at = NOW()
                WHERE review_id = ? AND reviewer_id = ? AND reviewer_type = 'volunteer'
            ");
            $stmt->bind_param("isii", $rating, $review_text, $review_id, $volunteer_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Review updated successfully';
            } else {
                $_SESSION['error'] = 'Failed to update review: ' . $stmt->error;
            }
            $stmt->close();
            
            header("Location: my_participation.php?tab=attendance");
            exit();
            break;
            
        case 'delete_review':
            $review_id = (int)$_POST['review_id'];
            
            // Delete review
            $stmt = $dbc->prepare("
                DELETE FROM reviews 
                WHERE review_id = ? AND reviewer_id = ? AND reviewer_type = 'volunteer'
            ");
            $stmt->bind_param("ii", $review_id, $volunteer_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Review deleted successfully';
            } else {
                $_SESSION['error'] = 'Failed to delete review';
            }
            $stmt->close();
            
            header("Location: my_participation.php?tab=attendance");
            exit();
            break;
    }
}

$page_title = "My Participaction";
require_once __DIR__ . '/views/layout/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Participation - Volunteer Connect</title>
    <link rel="stylesheet" href="/volcon/assets/css/applicants_manager.css">
    <link rel="stylesheet" href="/volcon/assets/css/participation_manager.css">
    <link rel="stylesheet" href="/volcon/assets/css/my_participation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/volcon/assets/js/my_participation.js" defer></script>
</head>
<body>
    <div class="vc-applicants-container">
        <!-- Header -->
        <div class="vc-page-header">
            <a href="dashboard_vol.php" class="vc-btn vc-btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <div>
                <h1><i class="fas fa-history"></i> My Participation History</h1>
                <p class="vc-subtitle">
                    Track your volunteer attendance, hours, and reviews
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="vc-stats-grid">
            <div class="vc-stat-card">
                <div class="vc-stat-value"><?= $stats['total'] ?></div>
                <div class="vc-stat-label">Total Participations</div>
            </div>
            <div class="vc-stat-card vc-stat-attended">
                <div class="vc-stat-value"><?= $stats['attended'] ?></div>
                <div class="vc-stat-label">Attended</div>
            </div>
            <div class="vc-stat-card vc-stat-pending">
                <div class="vc-stat-value"><?= $stats['pending'] ?></div>
                <div class="vc-stat-label">Pending</div>
            </div>
            <div class="vc-stat-card vc-stat-absent">
                <div class="vc-stat-value"><?= $stats['absent'] + $stats['incomplete'] ?></div>
                <div class="vc-stat-label">Absent/Incomplete</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="vc-vol-tabs">
            <a href="?tab=attendance" 
               class="vc-vol-tab <?= $current_tab === 'attendance' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i> Attendance History
                <span class="vc-tab-badge"><?= count($participation_history) ?></span>
            </a>
            <a href="?tab=reviews" 
               class="vc-vol-tab <?= $current_tab === 'reviews' ? 'active' : '' ?>">
                <i class="fas fa-star"></i> My Reviews
                <?php 
                $my_reviews_count = count(array_filter($participation_history, function($item) {
                    return !empty($item['vol_review_id']);
                }));
                ?>
                <?php if ($my_reviews_count > 0): ?>
                <span class="vc-tab-badge"><?= $my_reviews_count ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=organizations" 
               class="vc-vol-tab <?= $current_tab === 'organizations' ? 'active' : '' ?>">
                <i class="fas fa-building"></i> Organizations
                <?php 
                $unique_orgs = count(array_unique(array_column($participation_history, 'org_id')));
                ?>
                <span class="vc-tab-badge"><?= $unique_orgs ?></span>
            </a>
        </div>

        <!-- Tab Content -->
        <div class="vc-vol-tab-content">
            <?php if ($current_tab === 'attendance'): ?>
                <!-- ATTENDANCE TAB -->
                
                <!-- Filters -->
                <div class="vc-filters-panel">
                    <form method="get" id="filterForm">
                        <input type="hidden" name="tab" value="attendance">
                        <div class="vc-filters-row">
                            <div>
                                <div class="vc-search-wrapper">
                                    <input type="text" 
                                        name="search" 
                                        placeholder="Search organizations..." 
                                        value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" 
                                        class="vc-search-input">
                                    <i class="fas fa-search vc-search-icon"></i>
                                </div>

                                <select name="status" onchange="this.form.submit()">
                                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                    <option value="attended" <?= $filter_status === 'attended' ? 'selected' : '' ?>>Attended</option>
                                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="absent" <?= $filter_status === 'absent' ? 'selected' : '' ?>>Absent</option>
                                    <option value="incomplete" <?= $filter_status === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
                                </select>
                            </div>
                            <button type="submit" class="vc-btn vc-btn-primary">Filter</button>
                            <a href="?tab=attendance" class="vc-btn vc-btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Participation Table -->
                <?php if (empty($filtered_history)): ?>
                <div class="vc-empty-state">
                    <div class="vc-empty-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h3>No Participation Records</h3>
                    <p>
                        <?php if ($filter_status === 'all'): ?>
                        You haven't participated in any opportunities yet.
                        <?php else: ?>
                        No <?= $filter_status ?> participations found.
                        <?php endif; ?>
                    </p>
                    </br>
                    <a href="browse_opportunities.php" class="vc-btn vc-btn-primary mt-3">
                        <i class="fas fa-search"></i> Find Opportunities
                    </a>
                </div>
                
                <?php else: ?>
                <div class="vc-participation-table-container">
                    <div class="vc-table-header">
                        <div class="th th-date">Date</div>
                        <div class="th th-opportunity">Opportunity</div>
                        <div class="th th-organization">Organization</div>
                        <div class="th th-status">Status</div>
                        <div class="th th-hours">Hours</div>
                        <div class="th th-rating-vol">Your Rating</div>
                        <div class="th th-rating-org">Org's Rating</div>
                        <div class="th th-actions">Actions</div>
                    </div>
                    
                    <?php foreach ($filtered_history as $participation): ?>
                    <div class="vc-table-row" data-participation-id="<?= $participation['participation_id'] ?>">
                        <!-- Date -->
                        <div class="td td-date">
                            <div class="vc-date-main"><?= $participation['formatted_date'] ?></div>
                            <div class="vc-date-sub">
                                <?= $participation['formatted_start'] ?>
                                <?php if ($participation['formatted_end'] && $participation['formatted_end'] !== $participation['formatted_start']): ?>
                                    - <?= $participation['formatted_end'] ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Opportunity -->
                        <div class="td td-opportunity">
                            <div class="vc-opp-info">
                                <h4 class="vc-opp-title">
                                    <a href="view_opportunity.php?id=<?= $participation['opportunity_id'] ?>">
                                        <?= htmlspecialchars($participation['opportunity_title']) ?>
                                    </a>
                                </h4>
                                <div class="vc-opp-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> 
                                        <?= htmlspecialchars($participation['opp_city'] . ', ' . $participation['opp_state']) ?>
                                    </span>
                                    <?php if ($participation['time_range']): ?>
                                    <span><i class="fas fa-clock"></i> <?= $participation['time_range'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Organization -->
                        <div class="td td-organization">
                            <div class="vc-org-info">
                                <div class="vc-org-avatar">
                                    <img src="<?= $participation['org_avatar'] ?: '/volcon/assets/uploads/default-org.png' ?>" 
                                         alt="<?= htmlspecialchars($participation['org_name']) ?>">
                                </div>
                                <div class="vc-org-details">
                                    <div class="vc-org-name">
                                        <a href="profile_org.php?id=<?= $participation['org_id'] ?>">
                                            <?= htmlspecialchars($participation['org_name']) ?>
                                        </a>
                                    </div>
                                    <div class="vc-org-status">
                                        <span class="vc-badge vc-status-<?= $participation['opp_status'] ?>">
                                            <?= ucfirst($participation['opp_status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div class="td td-status">
                            <span class="vc-badge vc-badge-<?= $participation['status'] ?>">
                                <?= ucfirst($participation['status']) ?>
                            </span>
                            <?php if ($participation['reason']): ?>
                            <div class="vc-reason-tooltip">
                                <i class="fas fa-info-circle" 
                                   title="Reason: <?= ucfirst(str_replace('_', ' ', $participation['reason'])) ?>"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Hours -->
                        <div class="td td-hours">
                            <?php if (in_array($participation['status'], ['attended', 'incomplete']) && $participation['hours_worked']): ?>
                            <span class="vc-hours-badge">
                                <i class="fas fa-clock"></i> <?= $participation['hours_worked'] ?>h
                            </span>
                            <?php else: ?>
                            <span class="vc-hours-na">-</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Your Rating -->
                        <div class="td td-rating-vol">
                            <?php if ($participation['vol_review_id']): ?>
                            <div class="vc-rating-display">
                                <div class="vc-rating-stars vc-rating-small">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $participation['vol_rating']): ?>
                                            <i class="fas fa-star vc-star-filled"></i>
                                        <?php else: ?>
                                            <i class="far fa-star vc-star-empty"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <?php if ($participation['vol_review']): ?>
                                <div class="vc-review-preview" 
                                     title="<?= htmlspecialchars($participation['vol_review']) ?>">
                                    <i class="fas fa-comment"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($participation['can_rate_org']): ?>
                            <button class="vc-btn vc-btn-sm vc-btn-warning"
                                    onclick="showRateModal(
                                        <?= $participation['opportunity_id'] ?>,
                                        <?= $participation['org_id'] ?>,
                                        '<?= htmlspecialchars($participation['org_name']) ?>',
                                        '<?= htmlspecialchars($participation['opportunity_title']) ?>'
                                    )">
                                <i class="fas fa-star"></i> Rate
                            </button>
                            <?php else: ?>
                            <span class="vc-rating-na">-</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Organization's Rating -->
                        <div class="td td-rating-org">
                            <?php if ($participation['org_review_id']): ?>
                            <div class="vc-rating-display">
                                <div class="vc-rating-stars vc-rating-small">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $participation['org_rating']): ?>
                                            <i class="fas fa-star vc-star-filled"></i>
                                        <?php else: ?>
                                            <i class="far fa-star vc-star-empty"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <?php if ($participation['org_review']): ?>
                                <div class="vc-review-preview" 
                                     title="<?= htmlspecialchars($participation['org_review']) ?>">
                                    <i class="fas fa-comment"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <span class="vc-rating-na">Not rated yet</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Actions -->
                        <div class="td td-actions">
                            <div class="vc-action-buttons">
                                <a href="view_opportunity.php?id=<?= $participation['opportunity_id'] ?>" 
                                   class="vc-btn vc-btn-sm vc-btn-secondary"
                                   title="View Opportunity">
                                    <i class="fas fa-eye"></i>
                                    View Opportunity
                                </a>
                                
                                <a href="profile_org.php?id=<?= $participation['org_id'] ?>" 
                                   class="vc-btn vc-btn-sm vc-btn-secondary"
                                   title="View Organization">
                                    <i class="fas fa-building"></i>
                                    View Organization
                                </a>
                                
                                <?php if ($participation['feedback']): ?>
                                <button class="vc-btn vc-btn-sm vc-btn-info"
                                        onclick="showFeedback('<?= htmlspecialchars($participation['feedback']) ?>')"
                                        title="View Feedback">
                                    <i class="fas fa-comment-alt"></i>
                                    View Feedback
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($participation['vol_review_id']): ?>
                                <button class="vc-btn vc-btn-sm vc-btn-warning"
                                        onclick="showEditReviewModal(
                                            <?= $participation['vol_review_id'] ?>,
                                            <?= $participation['vol_rating'] ?>,
                                            '<?= htmlspecialchars($participation['vol_review'] ?? '') ?>',
                                            '<?= htmlspecialchars($participation['org_name']) ?>',
                                            '<?= htmlspecialchars($participation['opportunity_title']) ?>'
                                        )"
                                        title="Edit Review">
                                    <i class="fas fa-edit"></i>
                                    Edit Review
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Summary Stats -->
                <div class="vc-participation-summary">
                    <div class="vc-summary-item">
                        <span class="vc-summary-label">Total Hours:</span>
                        <span class="vc-summary-value">
                            <?= array_sum(array_column($filtered_history, 'hours_worked')) ?> hours
                        </span>
                    </div>
                    <div class="vc-summary-item">
                        <span class="vc-summary-label">Average Rating Given:</span>
                        <span class="vc-summary-value">
                            <?php 
                            $given_ratings = array_filter($filtered_history, function($item) {
                                return !empty($item['vol_rating']);
                            });
                            $avg_rating = $given_ratings 
                                ? number_format(array_sum(array_column($given_ratings, 'vol_rating')) / count($given_ratings), 1) 
                                : 0;
                            ?>
                            <?= $avg_rating > 0 ? $avg_rating . ' / 5.0' : 'Not rated yet' ?>
                        </span>
                    </div>
                    <div class="vc-summary-item">
                        <span class="vc-summary-label">Average Rating Received:</span>
                        <span class="vc-summary-value">
                            <?php 
                            $received_ratings = array_filter($filtered_history, function($item) {
                                return !empty($item['org_rating']);
                            });
                            $avg_received = $received_ratings ? 
                                number_format(array_sum(array_column($received_ratings, 'org_rating')) / count($received_ratings), 1) : 0;
                            ?>
                            <?= $avg_received ? $avg_received . ' / 5.0' : 'Not rated yet' ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php elseif ($current_tab === 'reviews'): ?>
                <!-- REVIEWS TAB -->
                
                <?php 
                $my_reviews = array_filter($participation_history, function($item) {
                    return !empty($item['vol_review_id']);
                });
                ?>
                
                <?php if (empty($my_reviews)): ?>
                <div class="vc-empty-state">
                    <div class="vc-empty-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>No Reviews Yet</h3>
                    <p>You haven't reviewed any organizations yet. Reviews can be submitted for completed opportunities you have attended.</p>
                    <a href="?tab=attendance" class="vc-btn vc-btn-primary mt-3">
                        <i class="fas fa-history"></i> View Attendance History
                    </a>
                </div>
                
                <?php else: ?>
                <div class="vc-reviews-container">
                    <div class="vc-reviews-header">
                        <h3>My Reviews (<?= count($my_reviews) ?>)</h3>
                        <p class="vc-subtitle">Reviews you've given to organizations</p>
                    </div>
                    
                    <div class="vc-reviews-grid">
                        <?php foreach ($my_reviews as $review): ?>
                        <div class="vc-review-card">
                            <div class="vc-review-header">
                                <div class="vc-review-org">
                                    <div class="vc-org-avatar-small">
                                        <img src="<?= $review['org_avatar'] ?: '/volcon/assets/uploads/default-org.png' ?>" 
                                             alt="<?= htmlspecialchars($review['org_name']) ?>">
                                    </div>
                                    <div class="vc-review-org-info">
                                        <h4 class="vc-org-name">
                                            <a href="profile_org.php?id=<?= $review['org_id'] ?>">
                                                <?= htmlspecialchars($review['org_name']) ?>
                                            </a>
                                        </h4>
                                        <p class="vc-opportunity-name">
                                            <a href="view_opportunity.php?id=<?= $review['opportunity_id'] ?>">
                                                <?= htmlspecialchars($review['opportunity_title']) ?>
                                            </a>
                                        </p>
                                    </div>
                                </div>
                                <div class="vc-review-rating">
                                    <div class="vc-rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php if ($i <= $review['vol_rating']): ?>
                                                <i class="fas fa-star vc-star-filled"></i>
                                            <?php else: ?>
                                                <i class="far fa-star vc-star-empty"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="vc-review-date">
                                        <?= date('M d, Y', strtotime($review['participated_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($review['vol_review'])): ?>
                            <div class="vc-review-body">
                                <p><?= nl2br(htmlspecialchars($review['vol_review'])) ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="vc-review-footer">
                                <div class="vc-review-meta">
                                    <span class="vc-review-status">
                                        <i class="fas fa-check-circle"></i> <?= ucfirst($review['status']) ?>
                                    </span>
                                    <?php if ($review['hours_worked']): ?>
                                    <span class="vc-review-hours">
                                        <i class="fas fa-clock"></i> <?= $review['hours_worked'] ?> hours
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="vc-review-actions">
                                    <button class="vc-btn vc-btn-sm vc-btn-warning"
                                            onclick="showEditReviewModal(
                                                <?= $review['vol_review_id'] ?>,
                                                <?= $review['vol_rating'] ?>,
                                                '<?= htmlspecialchars($review['vol_review'] ?? '') ?>',
                                                '<?= htmlspecialchars($review['org_name']) ?>',
                                                '<?= htmlspecialchars($review['opportunity_title']) ?>'
                                            )">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="vc-btn vc-btn-sm vc-btn-danger"
                                            onclick="confirmDeleteReview(<?= $review['vol_review_id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php elseif ($current_tab === 'organizations'): ?>
                <!-- ORGANIZATIONS TAB -->
                
                <?php 
                // Group participations by organization
                $organizations = [];
                foreach ($participation_history as $participation) {
                    $org_id = $participation['org_id'];
                    if (!isset($organizations[$org_id])) {
                        $organizations[$org_id] = [
                            'org_id' => $org_id,
                            'org_name' => $participation['org_name'],
                            'org_avatar' => $participation['org_avatar'],
                            'participations' => [],
                            'total_hours' => 0,
                            'attended_count' => 0,
                            'avg_vol_rating' => 0,
                            'avg_org_rating' => 0
                        ];
                    }
                    
                    $organizations[$org_id]['participations'][] = $participation;
                    $organizations[$org_id]['total_hours'] += $participation['hours_worked'] ?? 0;
                    
                    if ($participation['status'] === 'attended') {
                        $organizations[$org_id]['attended_count']++;
                    }
                    
                    // Collect ratings for averaging
                    if ($participation['vol_rating']) {
                        $organizations[$org_id]['vol_ratings'][] = $participation['vol_rating'];
                    }
                    if ($participation['org_rating']) {
                        $organizations[$org_id]['org_ratings'][] = $participation['org_rating'];
                    }
                }
                
                // Calculate averages
                foreach ($organizations as &$org) {
                    $org['avg_vol_rating'] = isset($org['vol_ratings']) ? 
                        round(array_sum($org['vol_ratings']) / count($org['vol_ratings']), 1) : 0;
                    $org['avg_org_rating'] = isset($org['org_ratings']) ? 
                        round(array_sum($org['org_ratings']) / count($org['org_ratings']), 1) : 0;
                    unset($org['vol_ratings'], $org['org_ratings']);
                }
                ?>
                
                <?php if (empty($organizations)): ?>
                <div class="vc-empty-state">
                    <div class="vc-empty-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h3>No Organizations Yet</h3>
                    <p>You haven't participated in any opportunities with organizations yet.</p>
                    <a href="browse_opportunities.php" class="vc-btn vc-btn-primary mt-3">
                        <i class="fas fa-search"></i> Find Opportunities
                    </a>
                </div>
                
                <?php else: ?>
                <div class="vc-organizations-container">
                    <div class="vc-orgs-header">
                        <h3>Organizations You've Worked With (<?= count($organizations) ?>)</h3>
                        <p class="vc-subtitle">Sorted by most recent participation</p>
                    </div>
                    
                    <div class="vc-orgs-grid">
                        <?php foreach ($organizations as $org): ?>
                        <div class="vc-org-card">
                            <div class="vc-org-card-header">
                                <div class="vc-org-avatar-medium">
                                    <img src="<?= $org['org_avatar'] ?: '/volcon/assets/uploads/default-org.png' ?>" 
                                         alt="<?= htmlspecialchars($org['org_name']) ?>">
                                </div>
                                <div class="vc-org-card-info">
                                    <h4 class="vc-org-name">
                                        <a href="profile_org.php?id=<?= $org['org_id'] ?>">
                                            <?= htmlspecialchars($org['org_name']) ?>
                                        </a>
                                    </h4>
                                    <div class="vc-org-stats">
                                        <span class="vc-org-stat">
                                            <i class="fas fa-history"></i> 
                                            <?= count($org['participations']) ?> <?= count($org['participations']) !== 1 ? 'opportunities' : 'opportunity' ?>
                                        </span>
                                        <span class="vc-org-stat">
                                            <i class="fas fa-clock"></i> 
                                            <?= $org['total_hours'] ?> hours
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="vc-org-card-body">
                                <div class="vc-org-rating-summary">
                                    <div class="vc-org-rating-item">
                                        <span class="vc-rating-label">Your Average Rating:</span>
                                        <div class="vc-rating-stars vc-rating-small">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= floor($org['avg_vol_rating'])): ?>
                                                    <i class="fas fa-star vc-star-filled"></i>
                                                <?php elseif ($i - 0.5 <= $org['avg_vol_rating']): ?>
                                                    <i class="fas fa-star-half-alt vc-star-filled"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star vc-star-empty"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <span class="vc-rating-score"><?= number_format($org['avg_vol_rating'], 1) ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="vc-org-rating-item">
                                        <span class="vc-rating-label">Their Average Rating:</span>
                                        <div class="vc-rating-stars vc-rating-small">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= floor($org['avg_org_rating'])): ?>
                                                    <i class="fas fa-star vc-star-filled"></i>
                                                <?php elseif ($i - 0.5 <= $org['avg_org_rating']): ?>
                                                    <i class="fas fa-star-half-alt vc-star-filled"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star vc-star-empty"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <span class="vc-rating-score"><?= number_format($org['avg_org_rating'], 1) ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="vc-org-recent-participation">
                                    <span class="vc-recent-label">Most Recent:</span>
                                    <?php 
                                    $most_recent = $org['participations'][0];
                                    $status_badge = in_array($most_recent['status'], ['attended', 'incomplete']) ? 
                                        'vc-badge-success' : 'vc-badge-warning';
                                    ?>
                                    <span class="vc-badge <?= $status_badge ?>">
                                        <?= ucfirst($most_recent['status']) ?>
                                    </span>
                                    <span class="vc-recent-date">
                                        <?= date('M d, Y', strtotime($most_recent['participated_at'])) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="vc-org-card-footer">
                                <div class="vc-org-actions">
                                    <a href="profile_org.php?id=<?= $org['org_id'] ?>" 
                                       class="vc-btn vc-btn-sm vc-btn-primary">
                                        View Profile
                                    </a>
                                    <button class="vc-btn vc-btn-sm vc-btn-secondary"
                                            onclick="startChatWith(
                                                <?= (int)$org['org_id'] ?>,
                                                'org',
                                                '<?= esc($org['org_name']) ?>',
                                                '<?= esc($org['org_avatar'] ?: '/volcon/assets/uploads/default-avatar.png') ?>'
                                            )">
                                        <i class="fas fa-comment"></i> Message
                                    </button>
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

    <!-- Modals -->
    
    <!-- Rate Organization Modal -->
    <div id="rateModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3><i class="fas fa-star"></i> Rate Your Experience</h3>
                <button class="vc-modal-close" onclick="closeModal('rateModal')">&times;</button>
            </div>
            <div class="vc-modal-body">
                <form id="rateForm" method="POST">
                    <input type="hidden" name="action" value="rate_organization">
                    <input type="hidden" id="rateOpportunityId" name="opportunity_id">
                    <input type="hidden" id="rateOrgId" name="org_id">
                    
                    <div class="vc-rate-info">
                        <h4 id="rateOrgName"></h4>
                        <p id="rateOppTitle" class="vc-rate-subtitle"></p>
                    </div>
                    
                    <div class="vc-rate-stars-large" id="starRating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star" data-rating="<?= $i ?>"></i>
                        <?php endfor; ?>
                        <span class="vc-rating-label">Click to rate</span>
                    </div>
                    <input type="hidden" id="ratingValue" name="rating" value="0" required>
                    
                    <div class="vc-rate-review">
                        <label for="reviewText">Your Review (Optional):</label>
                        <textarea id="reviewText" name="review_text" rows="4" 
                                  placeholder="Share your experience with this organization..."></textarea>
                    </div>
                </form>
            </div>
            <div class="vc-modal-footer">
                <button class="vc-btn vc-btn-secondary" onclick="closeModal('rateModal')">Cancel</button>
                <button class="vc-btn vc-btn-primary" onclick="submitRating()">Submit Review</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Review Modal -->
    <div id="editReviewModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3><i class="fas fa-edit"></i> Edit Review</h3>
                <button class="vc-modal-close" onclick="closeModal('editReviewModal')">&times;</button>
            </div>
            <div class="vc-modal-body">
                <form id="editReviewForm" method="POST">
                    <input type="hidden" name="action" value="update_review">
                    <input type="hidden" id="editReviewId" name="review_id">
                    
                    <div class="vc-rate-info">
                        <h4 id="editOrgName"></h4>
                        <p id="editOppTitle" class="vc-rate-subtitle"></p>
                    </div>
                    
                    <div class="vc-rate-stars-large" id="editStarRating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star" data-rating="<?= $i ?>"></i>
                        <?php endfor; ?>
                        <span class="vc-rating-label">Click to rate</span>
                    </div>
                    <input type="hidden" id="editRatingValue" name="rating" value="0" required>
                    
                    <div class="vc-rate-review">
                        <label for="editReviewText">Your Review:</label>
                        <textarea id="editReviewText" name="review_text" rows="4" 
                                  placeholder="Share your experience with this organization..."></textarea>
                    </div>
                </form>
            </div>
            <div class="vc-modal-footer">
                <button class="vc-btn vc-btn-secondary" onclick="closeModal('editReviewModal')">Cancel</button>
                <button class="vc-btn vc-btn-primary" onclick="submitEditReview()">Update Review</button>
            </div>
        </div>
    </div>
    
    <!-- Feedback Modal -->
    <div id="feedbackModal" class="vc-modal">
        <div class="vc-modal-content">
            <div class="vc-modal-header">
                <h3><i class="fas fa-comment-alt"></i> Organization's Feedback</h3>
                <button class="vc-modal-close" onclick="closeModal('feedbackModal')">&times;</button>
            </div>
            <div class="vc-modal-body">
                <div class="vc-feedback-content" id="feedbackContent"></div>
            </div>
            <div class="vc-modal-footer">
                <button class="vc-btn vc-btn-primary" onclick="closeModal('feedbackModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="vc-alert vc-alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="vc-alert vc-alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
    </div>
    <?php unset($_SESSION['error']); endif; ?>
    
    <script>
    // Global variables
    let currentRating = 0;
    let editRating = 0;
    
    // Show rate modal
    function showRateModal(opportunityId, orgId, orgName, oppTitle) {
        document.getElementById('rateOpportunityId').value = opportunityId;
        document.getElementById('rateOrgId').value = orgId;
        document.getElementById('rateOrgName').textContent = orgName;
        document.getElementById('rateOppTitle').textContent = oppTitle;
        
        // Reset stars
        currentRating = 0;
        document.querySelectorAll('#starRating .fa-star').forEach(star => {
            star.className = 'far fa-star';
        });
        document.getElementById('ratingValue').value = 0;
        document.getElementById('reviewText').value = '';
        
        document.getElementById('rateModal').style.display = 'flex';
    }
    
    // Show edit review modal
    function showEditReviewModal(reviewId, currentRatingValue, currentReview, orgName, oppTitle) {
        document.getElementById('editReviewId').value = reviewId;
        document.getElementById('editOrgName').textContent = orgName;
        document.getElementById('editOppTitle').textContent = oppTitle;
        
        // Set current rating
        editRating = currentRatingValue;
        document.getElementById('editRatingValue').value = currentRatingValue;
        document.getElementById('editReviewText').value = currentReview || '';
        
        // Highlight stars
        highlightStars('editStarRating', currentRatingValue);
        updateRatingLabel('editStarRating', currentRatingValue);
        
        document.getElementById('editReviewModal').style.display = 'flex';
    }
    
    // Show feedback modal
    function showFeedback(feedback) {
        document.getElementById('feedbackContent').textContent = feedback;
        document.getElementById('feedbackModal').style.display = 'flex';
    }
    
    // Close modal
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // Initialize star ratings
    document.addEventListener('DOMContentLoaded', function() {
        // Rate modal stars
        const stars = document.querySelectorAll('#starRating .fa-star');
        stars.forEach(star => {
            star.addEventListener('mouseover', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                highlightStars('starRating', rating);
                updateRatingLabel('starRating', rating);
            });
            
            star.addEventListener('click', function() {
                currentRating = parseInt(this.getAttribute('data-rating'));
                document.getElementById('ratingValue').value = currentRating;
                highlightStars('starRating', currentRating);
                updateRatingLabel('starRating', currentRating);
            });
        });
        
        document.getElementById('starRating').addEventListener('mouseleave', function() {
            highlightStars('starRating', currentRating);
            updateRatingLabel('starRating', currentRating);
        });
        
        // Edit modal stars
        const editStars = document.querySelectorAll('#editStarRating .fa-star');
        editStars.forEach(star => {
            star.addEventListener('mouseover', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                highlightStars('editStarRating', rating);
                updateRatingLabel('editStarRating', rating);
            });
            
            star.addEventListener('click', function() {
                editRating = parseInt(this.getAttribute('data-rating'));
                document.getElementById('editRatingValue').value = editRating;
                highlightStars('editStarRating', editRating);
                updateRatingLabel('editStarRating', editRating);
            });
        });
        
        document.getElementById('editStarRating').addEventListener('mouseleave', function() {
            highlightStars('editStarRating', editRating);
            updateRatingLabel('editStarRating', editRating);
        });
    });
    
    function highlightStars(containerId, rating) {
        const stars = document.querySelectorAll(`#${containerId} .fa-star`);
        stars.forEach((star, index) => {
            const starNum = index + 1;
            if (starNum <= rating) {
                star.className = 'fas fa-star';
            } else {
                star.className = 'far fa-star';
            }
        });
    }
    
    function updateRatingLabel(containerId, rating) {
        const labels = [
            'Click to rate',
            'Poor',
            'Fair',
            'Good',
            'Very Good',
            'Excellent'
        ];
        document.querySelector(`#${containerId} .vc-rating-label`).textContent = labels[rating];
    }
    
    function submitRating() {
        if (currentRating === 0) {
            alert('Please select a rating');
            return;
        }
        document.getElementById('rateForm').submit();
    }
    
    function submitEditReview() {
        if (editRating === 0) {
            alert('Please select a rating');
            return;
        }
        document.getElementById('editReviewForm').submit();
    }
    
    function confirmDeleteReview(reviewId) {
        if (confirm('Are you sure you want to delete this review? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_review">
                <input type="hidden" name="review_id" value="${reviewId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Auto-remove alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.vc-alert').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
    </script>

<?php require_once __DIR__ . '/views/layout/footer.php'; ?>