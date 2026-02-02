<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/api/notify.php';


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

/* ---------------------------------------------
   Handle POST actions
   --------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (
        isset($_POST['bulk_action'], $_POST['application_ids']) &&
        is_array($_POST['application_ids'])
    ) {
        $bulk_action = $_POST['bulk_action'];
        $application_ids = array_map('intval', $_POST['application_ids']);

        $allowed = [
            'accept'    => 'accepted',
            'shortlist' => 'shortlisted',
            'reject'    => 'rejected'
        ];

        if (!array_key_exists($bulk_action, $allowed) || empty($application_ids)) {
            flash('error', 'Invalid bulk action.');
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }

        $ids_list   = implode(',', $application_ids);
        $new_status = $allowed[$bulk_action];

        $dbc->begin_transaction();

        try {
            /* -----------------------------
            Update applications
            ----------------------------- */
            $stmt = $dbc->prepare("
                UPDATE applications a
                JOIN opportunities o ON o.opportunity_id = a.opportunity_id
                SET a.status = ?, a.response_at = NOW()
                WHERE a.application_id IN ({$ids_list})
                AND o.org_id = ?
            ");
            $stmt->bind_param("si", $new_status, $org_id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to update applications.');
            }
            $affected = $stmt->affected_rows;
            $stmt->close();

            /* -----------------------------
            Create participation (accept)
            ----------------------------- */
            if ($new_status === 'accepted') {
                if (!$dbc->query("
                    INSERT INTO participation (volunteer_id, opportunity_id, status)
                    SELECT a.volunteer_id, a.opportunity_id, 'pending'
                    FROM applications a
                    WHERE a.application_id IN ({$ids_list})
                    AND NOT EXISTS (
                        SELECT 1 FROM participation p
                        WHERE p.volunteer_id = a.volunteer_id
                        AND p.opportunity_id = a.opportunity_id
                    )
                ")) {
                    throw new Exception('Failed to create participation records.');
                }
            }

            /* -----------------------------
            Fetch for notifications
            ----------------------------- */
            $stmt = $dbc->prepare("
                SELECT a.volunteer_id, o.opportunity_id, o.title
                FROM applications a
                JOIN opportunities o ON o.opportunity_id = a.opportunity_id
                WHERE a.application_id IN ({$ids_list})
            ");
            if (!$stmt->execute()) {
                throw new Exception('Failed to fetch notification data.');
            }
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            /* -----------------------------
            Commit BEFORE notifying
            ----------------------------- */
            $dbc->commit();

            /* -----------------------------
            Send notifications
            ----------------------------- */
            foreach ($rows as $row) {
                createNotification([
                    'user_id' => (int)$row['volunteer_id'],
                    'role_target' => 'vol',
                    'title' => ucfirst($new_status),
                    'message' => "Your application for \"{$row['title']}\" was {$new_status}.",
                    'type' =>
                        $new_status === 'accepted' ? 'success' :
                        ($new_status === 'rejected' ? 'warning' : 'info'),
                    'action_url' => "/volcon/app/my_applications.php",
                    'context_type' => 'opportunity',
                    'context_id' => $row['opportunity_id'],
                    'is_dismissible' => 1,
                    'created_by' => 'organization',
                    'created_by_id' => $org_id
                ]);
            }

            flash('success', "Updated {$affected} application(s) to {$new_status}.");

        } catch (Throwable $e) {
            $dbc->rollback();
            flash('error', $e->getMessage());
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    
    // Single action
    if (isset($_POST['action'], $_POST['application_id'])) {
        $action = $_POST['action'];
        $application_id = (int)$_POST['application_id'];
        
        $allowed = ['accept' => 'accepted', 'shortlist' => 'shortlisted', 'reject' => 'rejected'];
        
        if (array_key_exists($action, $allowed)) {
            // Verify ownership
            $stmt = $dbc->prepare("
                SELECT
                    a.application_id,
                    a.opportunity_id,
                    a.volunteer_id,
                    o.title AS opportunity_title
                FROM applications a
                JOIN opportunities o ON o.opportunity_id = a.opportunity_id
                WHERE a.application_id = ? AND o.org_id = ?
            ");
            $stmt->bind_param("ii", $application_id, $org_id);
            $stmt->execute();
            $app = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($app) {
                // Check slot availability for accept action
                if ($action === 'accept') {
                    $dbc->begin_transaction();

                    try {
                        /* ----------------------------------
                        Slot availability check
                        ---------------------------------- */
                        $stmt = $dbc->prepare("
                            SELECT 
                                (SELECT COUNT(*) FROM applications 
                                WHERE opportunity_id = ? AND status = 'accepted') AS accepted_count,
                                o.number_of_volunteers
                            FROM opportunities o
                            WHERE o.opportunity_id = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param("ii", $app['opportunity_id'], $app['opportunity_id']);
                        $stmt->execute();
                        $res = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if (
                            $res['number_of_volunteers'] !== null &&
                            $res['accepted_count'] >= $res['number_of_volunteers']
                        ) {
                            throw new Exception('Cannot accept: slots are full.');
                        }

                        /* ----------------------------------
                        Update application status
                        ---------------------------------- */
                        $stmt = $dbc->prepare("
                            UPDATE applications 
                            SET status = 'accepted'
                            WHERE application_id = ?
                        ");
                        $stmt->bind_param("i", $application_id);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update application.');
                        }
                        $stmt->close();

                        /* ----------------------------------
                        Auto-create participation row
                        ---------------------------------- */
                        $stmt = $dbc->prepare("
                            INSERT INTO participation (volunteer_id, opportunity_id, status)
                            SELECT a.volunteer_id, a.opportunity_id, 'pending'
                            FROM applications a
                            WHERE a.application_id = ?
                            AND NOT EXISTS (
                                SELECT 1 FROM participation p
                                WHERE p.volunteer_id = a.volunteer_id
                                    AND p.opportunity_id = a.opportunity_id
                            )
                        ");
                        $stmt->bind_param("i", $application_id);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to create participation record.');
                        }
                        $stmt->close();

                        $dbc->commit();

                        createNotification([
                            'user_id' => (int)$app['volunteer_id'],
                            'role_target' => 'vol',
                            'title' => 'Application Accepted 🎉',
                            'message' => "You have been accepted for \"{$app['opportunity_title']}\".",
                            'type' => 'success',
                            'action_url' => "/volcon/app/my_participation.php",
                            'context_type' => 'opportunity',
                            'context_id' => $app['opportunity_id'],
                            'is_dismissible' => 1,
                            'created_by' => 'organization',
                            'created_by_id' => $org_id
                        ]);

                        flash('success', 'Applicant accepted and participation record created.');

                    } catch (Exception $e) {
                        $dbc->rollback();
                        flash('error', $e->getMessage());
                    }

                } elseif ($action === 'shortlist' || $action === 'reject') {
                    // Handle shortlist and reject actions
                    $new_status = $allowed[$action]; // 'shortlisted' or 'rejected'
                    
                    $stmt = $dbc->prepare("
                        UPDATE applications 
                        SET status = ?, response_at = NOW()
                        WHERE application_id = ?
                    ");
                    $stmt->bind_param("si", $new_status, $application_id);
                    
                    if ($stmt->execute()) {
                        flash('success', "Application {$new_status}.");

                        if ($new_status === 'shortlisted') {
                            createNotification([
                                'user_id' => (int)$app['volunteer_id'],
                                'role_target' => 'vol',
                                'title' => 'You Have Been Shortlisted ⭐',
                                'message' => "You were shortlisted for \"{$app['opportunity_title']}\".",
                                'type' => 'info',
                                'action_url' => "/volcon/app/my_applications.php",
                                'context_type' => 'opportunity',
                                'context_id' => $app['opportunity_id'],
                                'is_dismissible' => 1,
                                'created_by' => 'organization',
                                'created_by_id' => $org_id
                            ]);
                        }
                        elseif ($new_status === 'rejected') {
                            createNotification([
                                'user_id' => (int)$app['volunteer_id'],
                                'role_target' => 'vol',
                                'title' => 'Application Update',
                                'message' => "Your application for \"{$app['opportunity_title']}\" was not successful.",
                                'type' => 'warning',
                                'action_url' => "/volcon/app/my_applications.php",
                                'context_type' => 'opportunity',
                                'context_id' => $app['opportunity_id'],
                                'is_dismissible' => 1,
                                'created_by' => 'organization',
                                'created_by_id' => $org_id
                            ]);
                        }
                    } else {
                        flash('error', 'Failed to update application.');
                    }
                    $stmt->close();
                }
            }
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$page_title = "Applicants Manager";
require_once __DIR__ . '/views/layout/header.php';

/* ----------------------------
   Filters & Parameters
   ---------------------------- */
$filter_opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_gender = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$min_match = isset($_GET['min_match']) ? (int)$_GET['min_match'] : 0;
$max_age = isset($_GET['max_age']) ? (int)$_GET['max_age'] : 0;
$min_age = isset($_GET['min_age']) ? (int)$_GET['min_age'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'applied_desc';

$op_meta = null;

if ($filter_opportunity_id) {
    $stmt = $dbc->prepare("
        SELECT opportunity_id, title, status, number_of_volunteers
        FROM opportunities
        WHERE opportunity_id = ?
          AND org_id = ?
          AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $filter_opportunity_id, $org_id);
    $stmt->execute();
    $op_meta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ----------------------------
   Build WHERE clause
   ---------------------------- */
$where = [];
if ($filter_opportunity_id) {
    $where[] = "o.opportunity_id = " . (int)$filter_opportunity_id;
} else {
    $where[] = "o.org_id = " . (int)$org_id;
}

if ($filter_status && in_array($filter_status, ['pending','accepted','shortlisted','rejected','withdrawn'])) {
    $where[] = "a.status = '" . $dbc->real_escape_string($filter_status) . "'";
}

if ($filter_gender && in_array($filter_gender, ['m','f'])) {
    $where[] = "v.gender = '" . $dbc->real_escape_string($filter_gender) . "'";
}

if ($search !== '') {
    $s = $dbc->real_escape_string($search);
    $where[] = "(
        v.first_name LIKE '%{$s}%' 
        OR v.last_name LIKE '%{$s}%'
        OR v.city LIKE '%{$s}%'
        OR v.state LIKE '%{$s}%'
    )";
}

// ONLY include opportunities in these statuses
$allowed_op_statuses = ['open','closed','ongoing'];
$where[] = "o.status IN ('" . implode("','", $allowed_op_statuses) . "')";

$where_sql = implode(" AND ", $where);

/* ----------------------------
   Main Query (Optimized)
   ---------------------------- */
$sql = "
    SELECT
        a.application_id,
        a.status AS application_status,
        a.applied_at,

        v.vol_id,
        v.first_name,
        v.last_name,
        v.gender,
        v.city AS vol_city,
        v.state AS vol_state,
        v.availability,
        v.birthdate,
        v.profile_picture,
        TIMESTAMPDIFF(YEAR, v.birthdate, CURDATE()) AS age,

        o.opportunity_id,
        o.title AS opportunity_title,
        o.city AS opp_city,
        o.state AS opp_state,
        o.number_of_volunteers,
        o.status AS opp_status,

        (SELECT COUNT(*) 
        FROM applications
        WHERE opportunity_id = o.opportunity_id
            AND status = 'accepted'
        ) AS accepted_count,

        -- Participation / Experience
        (SELECT COUNT(*) 
        FROM participation p
        WHERE p.volunteer_id = v.vol_id
            AND p.status = 'attended'
        ) AS completed_count,


        -- Reviews
        (SELECT ROUND(AVG(r.rating), 2)
        FROM reviews r
        WHERE r.reviewee_type = 'volunteer'
            AND r.reviewee_id = v.vol_id
        ) AS avg_rating,

        (SELECT COUNT(*)
        FROM reviews r
        WHERE r.reviewee_type = 'volunteer'
            AND r.reviewee_id = v.vol_id
        ) AS review_count,


        -- Skills
        (SELECT COUNT(DISTINCT vs.skill_id)
        FROM volunteer_skills vs
        JOIN opportunity_skills os ON os.skill_id = vs.skill_id
        WHERE vs.vol_id = v.vol_id
            AND os.opportunity_id = o.opportunity_id
        ) AS skill_matches,

        (SELECT COUNT(*)
        FROM opportunity_skills
        WHERE opportunity_id = o.opportunity_id
        ) AS opp_skill_count,


        -- Interests
        (SELECT COUNT(DISTINCT vi.interest_id)
        FROM volunteer_interests vi
        JOIN opportunity_interests oi ON oi.interest_id = vi.interest_id
        WHERE vi.vol_id = v.vol_id
            AND oi.opportunity_id = o.opportunity_id
        ) AS interest_matches,

        (SELECT COUNT(*)
        FROM opportunity_interests
        WHERE opportunity_id = o.opportunity_id
        ) AS opp_interest_count


    FROM applications a
    JOIN volunteers v ON v.vol_id = a.volunteer_id
    JOIN opportunities o ON o.opportunity_id = a.opportunity_id

    -- Participation
    LEFT JOIN participation p 
        ON p.volunteer_id = v.vol_id

    -- Reviews
    LEFT JOIN reviews r
        ON r.reviewee_type = 'volunteer' AND r.reviewee_id = v.vol_id

    -- Accepted applications (for slot count)
    LEFT JOIN applications a2
        ON a2.opportunity_id = o.opportunity_id

    -- Skills
    LEFT JOIN volunteer_skills vs 
        ON vs.vol_id = v.vol_id
    LEFT JOIN opportunity_skills os
        ON os.opportunity_id = o.opportunity_id AND os.skill_id = vs.skill_id

    -- Interests
    LEFT JOIN volunteer_interests vi
        ON vi.vol_id = v.vol_id
    LEFT JOIN opportunity_interests oi
        ON oi.opportunity_id = o.opportunity_id AND oi.interest_id = vi.interest_id

    WHERE {$where_sql}
        AND o.status IN ('open','closed','ongoing')

    GROUP BY a.application_id
";


$stmt = $dbc->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ----------------------------
   Post-process: Calculate match scores & filter
   ---------------------------- */
$processed_rows = [];
foreach ($rows as $r) {
    $age = (int)$r['age'];
    
    // Age filters
    if ($min_age > 0 && $age < $min_age) continue;
    if ($max_age > 0 && $age > $max_age) continue;
    

    /* -------- Core ratios -------- */
    $skill_ratio = $r['opp_skill_count'] > 0
        ? $r['skill_matches'] / $r['opp_skill_count']
        : 0;

    $interest_ratio = $r['opp_interest_count'] > 0
        ? $r['interest_matches'] / $r['opp_interest_count']
        : 0;

    $skill_ratio = min(1, $skill_ratio);
    $interest_ratio = min(1, $interest_ratio);


    /* -------- Availability -------- */
    $availability_match = 0;
    if ($r['availability'] === 'flexible') $availability_match = 1;
    elseif ($r['availability'] === 'weekends') $availability_match = 0.8;
    elseif ($r['availability'] === 'part-time') $availability_match = 0.6;
    elseif ($r['availability'] === 'weekdays') $availability_match = 0.5;


    /* -------- Location -------- */
    $location_score = 0;
    if (!empty($r['vol_city']) && !empty($r['opp_city']) &&
        strcasecmp($r['vol_city'], $r['opp_city']) === 0) {
        $location_score = 1;
    } elseif (!empty($r['vol_state']) && !empty($r['opp_state']) &&
        strcasecmp($r['vol_state'], $r['opp_state']) === 0) {
        $location_score = 0.6;
    }


    /* -------- Experience -------- */
    $completed = (int)($r['completed_count'] ?? 0);
    $experience_score = min($completed / 5, 1); // cap at 5 events, not gonna demotivate new vol tho... this is just supporting factor


    /* -------- Rating -------- */
    $rating = (float)$r['avg_rating'];
    $reviews = (int)$r['review_count'];

    if ($reviews < 3) {
        $rating_score = 0.5; // neutral if too few reviews
    } else {
        $rating_score = max($rating / 5, 0.25);
    }


    /* -------- Final weighted score -------- */
    $match_score =
        ($skill_ratio * 0.40) +
        ($interest_ratio * 0.15) +
        ($availability_match * 0.10) +
        ($location_score * 0.10) +
        ($experience_score * 0.15) +
        ($rating_score * 0.10);

    $r['match_score'] = (int)round($match_score * 100);
    
    if ($min_match > 0 && $r['match_score'] < $min_match) continue;
    
    $processed_rows[] = $r;

    $tooltip = [
        'Skills' => round($skill_ratio * 0.40 * 100),
        'Interests' => round($interest_ratio * 0.15 * 100),
        'Availability' => round($availability_match * 0.10 * 100),
        'Location' => round($location_score * 0.10 * 100),
        'Experience' => round($experience_score * 0.15 * 100),
        'Rating' => round($rating_score * 0.10 * 100),
    ];

    $tooltip_str = implode(', ', array_map(
        fn($key, $val) => "$key: $val%", 
        array_keys($tooltip), 
        $tooltip
    ));
}

/* ----------------------------
   Sorting
   ---------------------------- */
if ($sort === 'match_desc') {
    usort($processed_rows, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
} elseif ($sort === 'age_asc') {
    usort($processed_rows, fn($a, $b) => $a['age'] <=> $b['age']);
} elseif ($sort === 'age_desc') {
    usort($processed_rows, fn($a, $b) => $b['age'] <=> $a['age']);
}

/* ----------------------------
   Group by opportunity
   ---------------------------- */
$grouped = [];
foreach ($processed_rows as $r) {
    $opid = $r['opportunity_id'];
    if (!isset($grouped[$opid])) {
        $grouped[$opid] = [
            'opportunity_id' => $opid,
            'title' => $r['opportunity_title'],
            'opp_city' => $r['opp_city'],
            'opp_state' => $r['opp_state'],
            'slots' => $r['number_of_volunteers'],
            'accepted' => $r['accepted_count'],
            'status' => $r['opp_status'],
            'rows' => []
        ];
    }
    $grouped[$opid]['rows'][] = $r;
}

/* ----------------------------
   Get all org opportunities for filter
   ---------------------------- */
$ops_stmt = $dbc->prepare("
    SELECT opportunity_id, title, status
    FROM opportunities
    WHERE org_id = ?
        AND status IN ('open','closed','ongoing')
        AND status != 'deleted'
    ORDER BY created_at DESC"
);

$ops_stmt->bind_param("i", $org_id);
$ops_stmt->execute();
$org_ops = $ops_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ops_stmt->close();

/* ----------------------------
   Statistics
   ---------------------------- */
$stats = [
    'total' => count($processed_rows),
    'pending' => count(array_filter($processed_rows, fn($r) => $r['application_status'] === 'pending')),
    'shortlisted' => count(array_filter($processed_rows, fn($r) => $r['application_status'] === 'shortlisted')),
    'accepted' => count(array_filter($processed_rows, fn($r) => $r['application_status'] === 'accepted')),
];
?>

<link rel="stylesheet" href="/volcon/assets/css/applicants_manager.css">

<div class="vc-applicants-container">
    <!-- Header -->
    <div class="vc-page-header">
        <div>
            <a href="dashboard_org.php" class="vc-btn vc-btn-secondary">← Back to Dashboard</a>
            <a href="participation_manager.php?id=<?= $filter_opportunity_id ?>" class="vc-btn vc-btn-secondary">
                <i class="fas fa-user-check"></i> View Participation
            </a>
        </div>
        <div>
            <h1>Applicants Manager</h1>
            <p class="vc-subtitle">
                <?php if ($filter_opportunity_id): ?>
                    Managing: <a href="view_opportunity.php?id=<?= $filter_opportunity_id ?>"><strong><?= esc($op_meta['title'] ?? 'Unknown') ?></strong></a>
                    <a href="?">(View All)</a>
                <?php else: ?>
                    Viewing all applicants across opportunities
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="vc-stats-grid">
        <div class="vc-stat-card">
            <div class="vc-stat-value"><?= $stats['total'] ?></div>
            <div class="vc-stat-label">Total Applications</div>
        </div>
        <div class="vc-stat-card vc-stat-pending">
            <div class="vc-stat-value"><?= $stats['pending'] ?></div>
            <div class="vc-stat-label">Pending Review</div>
        </div>
        <div class="vc-stat-card vc-stat-shortlisted">
            <div class="vc-stat-value"><?= $stats['shortlisted'] ?></div>
            <div class="vc-stat-label">Shortlisted</div>
        </div>
        <div class="vc-stat-card vc-stat-accepted">
            <div class="vc-stat-value"><?= $stats['accepted'] ?></div>
            <div class="vc-stat-label">Accepted</div>
        </div>
    </div>

    <div>
        <?php if ($op_meta && $filter_opportunity_id): ?>
            <?php if ($op_meta['status'] === 'closed' && empty($processed_rows)): ?>
                <div class="vc-alert vc-alert-warning">
                    <i class="fas fa-lock"></i>
                    This opportunity is closed and received no applications. Reopen this opportunity is highly recommended.
                    <button type="button" class="vc-alert__close" aria-label="Close">
                        &times;
                    </button>
                </div>

            <?php elseif ($op_meta['status'] === 'closed'): ?>
                <div class="vc-alert vc-alert-warning">
                    <i class="fas fa-lock"></i>
                    This opportunity is closed. New applications are no longer accepted.
                    <button type="button" class="vc-alert__close" aria-label="Close">
                        &times;
                    </button>
                </div>

            <?php elseif (empty($processed_rows)): ?>
                <div class="vc-alert vc-alert-info">
                    <i class="fas fa-user-slash"></i>
                    No applications found for this opportunity.
                    <button type="button" class="vc-alert__close" aria-label="Close">
                        &times;
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($op_meta && isset($grouped[$filter_opportunity_id])): ?>
            <?php
                $g = $grouped[$filter_opportunity_id];
                if ($g['slots'] !== null && $g['accepted'] >= $g['slots']):
            ?>
                <div class="vc-alert vc-alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Volunteer slots are full (<?= $g['accepted'] ?>/<?= $g['slots'] ?>).
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    
    <div class="vc-filters-panel">
        <form method="get" id="filterForm">
            <?php if ($filter_opportunity_id): ?>
                <input type="hidden" name="id" value="<?= $filter_opportunity_id ?>">
            <?php endif; ?>
            
            <div class="vc-filters-grid">
                
                <!-- Search (full width) -->
                <div class="vc-filter-item vc-filter-search">
                    <label for="search">
                        <i class="fas fa-search"></i>
                        <span>Search Applicants</span>
                    </label>
                    <div class="vc-input-icon">
                        <i class="fas fa-search"></i>
                        <input 
                            type="text"
                            id="search"
                            name="search"
                            value="<?= esc($search) ?>"
                            placeholder="Name, city, or state"
                            title="Search by volunteer name, city, or state"
                        >
                    </div>
                </div>
                
                <!-- Column 1 -->
                <div class="vc-filter-item">
                    <label for="opportunity">
                        <i class="fas fa-briefcase"></i>
                        <span>Opportunity</span>
                    </label>
                    <select name="id" id="opportunity" title="Filter by opportunity">
                        <option value="">All Opportunities</option>
                        <?php foreach ($org_ops as $o): ?>
                            <option 
                                value="<?= $o['opportunity_id'] ?>" 
                                <?= $filter_opportunity_id == $o['opportunity_id'] ? 'selected' : '' ?>
                            >
                                <?= esc($o['title']) ?>
                                <?php if ($o['status'] === 'closed'): ?> (Closed)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Column 2 -->
                <div class="vc-filter-item">
                    <label for="status">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Status</span>
                    </label>
                    <select name="status" id="status" title="Filter by application status">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending','shortlisted','accepted','rejected','withdrawn'] as $s): ?>
                            <option value="<?= $s ?>" <?= $s === $filter_status ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Column 3 -->
                <div class="vc-filter-item">
                    <label for="gender">
                        <i class="fas fa-venus-mars"></i>
                        <span>Gender</span>
                    </label>
                    <select name="gender" id="gender" title="Filter by gender">
                        <option value="">All Genders</option>
                        <option value="m" <?= $filter_gender === 'm' ? 'selected' : '' ?>>Male</option>
                        <option value="f" <?= $filter_gender === 'f' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                
                <!-- Column 1 -->
                <div class="vc-filter-item">
                    <label for="min_age">
                        <i class="fas fa-birthday-cake"></i>
                        <span>Age Range</span>
                    </label>
                    <div class="vc-age-range">
                        <input 
                            type="number"
                            id="min_age"
                            name="min_age"
                            value="<?= $min_age ?: '' ?>"
                            placeholder="Min"
                            min="0"
                            max="100"
                            title="Minimum volunteer age"
                        >
                        <span>–</span>
                        <input 
                            type="number"
                            id="max_age"
                            name="max_age"
                            value="<?= $max_age ?: '' ?>"
                            placeholder="Max"
                            min="0"
                            max="100"
                            title="Maximum volunteer age"
                        >
                    </div>
                </div>
                
                <!-- Column 2 -->
                <div class="vc-filter-item">
                    <label for="min_match">
                        <i class="fas fa-percentage"></i>
                        <span>Min Match %</span>
                    </label>
                    <input 
                        type="number"
                        id="min_match"
                        name="min_match"
                        value="<?= $min_match ?: '' ?>"
                        placeholder="0-100"
                        min="0"
                        max="100"
                        title="Minimum match percentage"
                    >
                </div>
                
                <!-- Column 3 -->
                <div class="vc-filter-item">
                    <label for="sort">
                        <i class="fas fa-sort-amount-down"></i>
                        <span>Sort By</span>
                    </label>
                    <select name="sort" id="sort" title="Sort applicants">
                        <option value="applied_desc" <?= $sort === 'applied_desc' ? 'selected' : '' ?>>Latest Applied</option>
                        <option value="match_desc" <?= $sort === 'match_desc' ? 'selected' : '' ?>>Best Match</option>
                        <option value="age_asc" <?= $sort === 'age_asc' ? 'selected' : '' ?>>Age (Low → High)</option>
                        <option value="age_desc" <?= $sort === 'age_desc' ? 'selected' : '' ?>>Age (High → Low)</option>
                    </select>
                </div>
                
                <!-- Action Buttons (full width) -->
                <div class="vc-filter-buttons">
                    <button type="submit" class="vc-btn vc-btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="applicants_manager.php<?= $filter_opportunity_id ? '?id=' . $filter_opportunity_id : '' ?>" class="vc-btn vc-btn-secondary">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
                
            </div>
        </form>
    </div>



    <!-- Bulk Actions Bar -->
    <div class="vc-bulk-actions" id="bulkActionsBar" style="display:none;">
        <div class="vc-bulk-selected">
            <span id="selectedCount">0</span> selected
        </div>
        <form method="post" id="bulkActionForm">
            <select name="bulk_action" required>
                <option value="">Choose Action...</option>
                <option value="accept">Accept</option>
                <option value="shortlist">Shortlist</option>
                <option value="reject">Reject</option>
            </select>
            <button type="submit" class="vc-btn vc-btn-primary">Apply to Selected</button>
            <button type="button" class="vc-btn vc-btn-secondary" onclick="clearSelection()">Clear Selection</button>
        </form>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="vc-empty-state">
            <div class="vc-empty-icon">
                <i class="fas fa-inbox"></i>
            </div>
            <h3>No Applications Found</h3>
            <p>Try adjusting your filters or check back later for new applications.</p>
        </div>
    <?php else: ?>
        <!-- Accordion Opportunities -->
        <?php foreach ($grouped as $op): ?>
        
        <?php
            $op_status = $op['status'] ?? 'unknown';

            $op_status_safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($op_status));
            $op_status_class = "vc-status-{$op_status_safe}";

            $op_status_icon = match ($op_status) {
                'open' => 'fas fa-check-circle',
                'closed' => 'fas fa-lock',
                'ongoing' => 'fas fa-running',
                default => 'fas fa-info-circle'
            };
        ?>

        <div class="vc-opportunity-accordion">
            <div class="vc-accordion-header">
                <div class="vc-opp-info">
                    <h3><?= esc($op['title']) ?></h3>
                    <div class="vc-opp-meta">
                        <i class="fas fa-map-marker-alt"></i> <?= esc($op['opp_city']) ?>, <?= esc($op['opp_state']) ?> • 
                        <?= count($op['rows']) ?> applicants •
                        <?php if ($op['slots'] > 0): ?>
                            Slots: <?= $op['accepted'] ?>/<?= $op['slots'] ?>
                            <?php if ($op['accepted'] >= $op['slots']): ?>
                                <span class="vc-badge vc-indicator-danger">
                                    <i class="fas fa-ban"></i>
                                    FULL
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            Unlimited slots
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vc-accordion-actions">
                    <span class="vc-opportunity-status <?= esc($op_status_class) ?>">
                        <i class="<?= $op_status_icon ?>"></i>
                        <?= ucfirst(esc($op_status)) ?>
                    </span>

                    <a href="view_opportunity.php?id=<?= $op['opportunity_id'] ?>" class="vc-btn vc-btn-sm vc-btn-secondary" onclick="event.stopPropagation()">View Opportunity</a>
                    <span class="vc-accordion-icon">▼</span>
                </div>
            </div>
            
            <div class="vc-accordion-body">
                <div class="vc-applicants-table">
                    <!-- Table Header -->
                    <div class="vc-table-header">
                        <?php if($op['status'] === 'open'): ?>
                        <div class="th th-checkbox">
                            <input type="checkbox" class="vc-select-all-opp" data-opid="<?= $op['opportunity_id'] ?>" onclick="toggleOpportunitySelection(this)">
                        </div>
                        <?php else: ?>
                        <div class="th td-checkbox">
                            <input type="checkbox" class="vc-select-all-opp" disabled>
                        </div>
                        <?php endif; ?>
                        <div class="th th-volunteer">Volunteer</div>
                        <div class="th th-details">Details</div>
                        <div class="th th-applied">Applied</div>
                        <div class="th th-status">Status</div>
                        <div class="th th-match">Match Score</div>
                        <div class="th th-actions">Actions</div>
                    </div>
                    
                    <!-- Table Rows -->
                    <?php foreach ($op['rows'] as $row): ?>
                    <div class="vc-table-row" data-opid="<?= $op['opportunity_id'] ?>">
                        <?php if($op['status'] === 'open'): 
                            $is_disabled_checkbox = in_array($row['application_status'], ['withdrawn','rejected']); ?>
                            <div class="td td-checkbox">
                                <input
                                    type="checkbox"
                                    class="vc-applicant-checkbox"
                                    name="application_ids[]"
                                    value="<?= (int)$row['application_id'] ?>"
                                    data-opid="<?= (int)$op['opportunity_id'] ?>"
                                    data-status="<?= esc($row['application_status']) ?>"
                                    <?= $is_disabled_checkbox ? 'disabled' : '' ?>
                                    onchange="updateBulkActions()"
                                >
                            </div>
                        <?php else: ?>
                            <div class="td td-checkbox">
                                <input type="checkbox" class="vc-applicant-checkbox" disabled>
                            </div>
                        <?php endif; ?>
                        <div class="td td-volunteer">
                            <div class="vc-volunteer-info">
                                <?php if (!empty($row['profile_picture'])): ?>
                                <img 
                                    src="<?= esc($row['profile_picture']) ?>" 
                                    alt="<?= esc($row['first_name'] . ' ' . $row['last_name']) ?>" 
                                    class="vc-avatar-img"
                                >
                                <?php else: ?>
                                <div class="vc-avatar-fl">
                                    <?= strtoupper(
                                        mb_substr($row['first_name'], 0, 1) . 
                                        mb_substr($row['last_name'], 0, 1)
                                    ) ?>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="vc-volunteer-name">
                                        <a href="profile_vol.php?id=<?= $row['vol_id'] ?>">
                                            <?= esc($row['first_name'] . ' ' . $row['last_name']) ?>
                                        </a>
                                    </div>
                                    <div class="vc-volunteer-meta">
                                        <?php if ($row['gender'] === 'm'): ?>
                                            <i class="fas fa-mars"></i>
                                        <?php elseif ($row['gender'] === 'f'): ?>
                                            <i class="fas fa-venus"></i>
                                        <?php else: ?>
                                            <i class="fas fa-user"></i>
                                        <?php endif; ?>
                                        <?= esc($row['vol_city']) ?>, <?= esc($row['vol_state']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="td td-details">
                            <?php if (!empty($row['avg_rating'])): ?>
                            <div class="vc-detail-item">
                                <i class="fas fa-star"></i>
                                <?= number_format($row['avg_rating'], 1) ?>/5
                                (<?= (int)$row['completed_count'] ?> completed)
                            </div>
                            <?php else: ?>
                            <div class="vc-detail-item vc-muted">
                                <i class="fas fa-star-half-alt"></i>
                                No reviews yet
                            </div>
                            <?php endif; ?>
                            <div class="vc-detail-item" style="text-transform: lowercase;">
                                <i class="fas fa-birthday-cake"></i> 
                                <strong><?= $row['age'] ?></strong> year-old
                            </div>
                            <div class="vc-detail-item">
                                <i class="fas fa-calendar-alt"></i> 
                                <?= esc($row['availability']) ?>
                            </div>
                            <div class="vc-detail-item skills-match">
                                <i class="fas fa-briefcase"></i>
                                <?= $row['skill_matches'] ?>/<?= $row['opp_skill_count'] ?> skills
                                 • 
                                <i class="fas fa-heart"></i> 
                                <?= $row['interest_matches'] ?>/<?= $row['opp_interest_count'] ?> interests
                            </div>
                        </div>
                        
                        <div class="td td-applied">
                            <div class="applied-date">
                                <?= date('M d, Y', strtotime($row['applied_at'])) ?>
                                <div class="applied-ago">
                                    (<?= timeAgo($row['applied_at']) ?>)
                                </div>
                            </div>
                        </div>

                        
                        <div class="td td-status">
                            <span class="vc-badge vc-badge-<?= $row['application_status'] ?>">
                                <?= ucfirst($row['application_status']) ?>
                            </span>
                        </div>
                        
                        <div class="td td-match">
                            <div class="vc-match-score vc-match-<?= $row['match_score'] >= 70 ? 'high' : ($row['match_score'] >= 40 ? 'medium' : 'low') ?>"
                            title="<?= htmlspecialchars($tooltip_str) ?>">
                                <?= $row['match_score'] ?>%
                            </div>
                        </div>
                        
                        <div class="td vc-td-actions">
                            <?php
                            $status = $row['application_status'];
                            $opp_status = $op['status'];

                            $accepted_count = (int)$row['accepted_count'];
                            $slot_limit = (int)$row['number_of_volunteers'];
                            $slots_full = ($slot_limit > 0 && $accepted_count >= $slot_limit);

                            /* GLOBAL LOCK */
                            $op_locked = in_array($opp_status, ['canceled','deleted','suspended','completed']);
                            $op_review_only = in_array($opp_status, ['closed','ongoing']);
                            ?>

                            <?php if ($op_locked): ?>
                                <span class="vc-muted">No actions</span>

                            <?php elseif ($op_review_only): ?>

                                <?php if ($opp_status === 'ongoing' && $status === 'accepted'): ?>
                                    <a href="participation_manager.php?id=<?= $op['opportunity_id'] ?>"
                                    class="vc-btn vc-btn-sm vc-btn-secondary">
                                        See Participation
                                    </a>
                                <?php else: ?>
                                    <span class="vc-muted">No action. Review only.</span>
                                <?php endif; ?>

                            <?php else: /* OPEN opportunity */ ?>

                                <!-- ACCEPT -->
                                <?php if (in_array($status, ['pending','shortlisted'])): ?>
                                    <form method="post" class="vc-inline-form"
                                        onsubmit="return confirm('Accept this applicant?')">
                                        <input type="hidden" name="application_id" value="<?= $row['application_id'] ?>">
                                        <input type="hidden" name="action" value="accept">
                                        <button type="submit"
                                                class="vc-btn vc-btn-sm vc-btn-success"
                                                <?= $slots_full ? 'disabled title="Slots are full"' : '' ?>>
                                            Accept
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- SHORTLIST -->
                                <?php if ($status === 'pending'): ?>
                                    <form method="post" class="vc-inline-form"
                                        onsubmit="return confirm('Shortlist this applicant?')">
                                        <input type="hidden" name="application_id" value="<?= $row['application_id'] ?>">
                                        <input type="hidden" name="action" value="shortlist">
                                        <button type="submit" class="vc-btn vc-btn-sm vc-btn-warning">
                                            Shortlist
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- REJECT -->
                                <?php if (in_array($status, ['pending','shortlisted'])): ?>
                                    <form method="post" class="vc-inline-form"
                                        onsubmit="return confirm('Reject this applicant?')">
                                        <input type="hidden" name="application_id" value="<?= $row['application_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="vc-btn vc-btn-sm vc-btn-danger">
                                            Reject
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- ACCEPTED -->
                                <?php if ($status === 'accepted'): ?>
                                    <a href="participation_manager.php?id=<?= $op['opportunity_id'] ?>"
                                    class="vc-btn vc-btn-sm vc-btn-secondary">
                                        See Participation
                                    </a>
                                <?php endif; ?>

                                <!-- REJECTED -->
                                <?php if ($status === 'rejected'): ?>
                                    <!-- <form method="post" class="vc-inline-form"
                                        onsubmit="return confirm('Move back to pending?')">
                                        <input type="hidden" name="application_id" value="<//?= $row['application_id'] ?>">
                                        <input type="hidden" name="action" value="reconsider">
                                        <button type="submit" class="vc-btn vc-btn-sm vc-btn-secondary">
                                            Reconsider
                                        </button>
                                    </form> -->
                                    <span class="vc-muted">No action. Review only.</span>
                                <?php endif; ?>

                                <!-- WITHDRAWN -->
                                <?php if ($status === 'withdrawn'): ?>
                                    <span class="vc-muted">No action. Review only.</span>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="/volcon/assets/js/applicants_manager.js"></script>
<script src="/volcon/assets/js/utils/scroll-to-top.js"></script>


<?php require_once __DIR__ . '/views/layout/footer.php'; ?>