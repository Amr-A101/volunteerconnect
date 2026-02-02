<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/api/notify.php';


require_role('org');

$user = current_user();
$org_id = (int)$user['user_id'];

// for notifications
function notifyVolunteerParticipation(
    int $volunteer_id,
    int $opportunity_id,
    string $status,
    ?int $hours = null
) {
    $titles = [
        'attended'   => 'Attendance Confirmed',
        'absent'     => 'Marked Absent',
        'incomplete' => 'Attendance Incomplete'
    ];

    $messages = [
        'attended' =>
            "Your attendance has been marked as attended"
            . ($hours !== null ? " ({$hours} hour(s))" : '') . ".",
        'absent' =>
            "You were marked absent for this opportunity.",
        'incomplete' =>
            "Your attendance was marked incomplete. Please check details."
    ];

    if (!isset($titles[$status])) return;

    createNotification([
        'user_id'      => $volunteer_id,
        'role_target'  => 'volunteer',
        'title'        => $titles[$status],
        'message'      => $messages[$status],
        'type'         => $status === 'attended' ? 'success' : 'warning',
        'action_url'   => "/volcon/app/my_applications.php",
        'context_type' => 'participation',
        'context_id'   => $opportunity_id,
        'created_by'   => 'organization'
    ]);
}


/* =========================
   AUTO STATUS TRANSITIONS
========================= */
require_once __DIR__ . '/core/auto_opp_trans.php';
runOpportunityAutoTransitions($dbc, $org_id);

// Get opportunity ID from query parameter
$opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';

// Fetch opportunity details
$opportunity = null;
$accepted_volunteers = [];
$participation_records = [];
$stats = [
    'total_accepted' => 0,
    'pending' => 0,
    'attended' => 0,
    'absent' => 0,
    'incomplete' => 0
];


if ($opportunity_id > 0) {
    // Get opportunity details
    $stmt = $dbc->prepare("
        SELECT o.*,
               (SELECT COUNT(*) 
                FROM applications a 
                WHERE a.opportunity_id = o.opportunity_id 
                AND a.status = 'accepted') as accepted_count
        FROM opportunities o
        WHERE o.opportunity_id = ? AND o.org_id = ?
    ");
    $stmt->bind_param("ii", $opportunity_id, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $opportunity = $result->fetch_assoc();
    $stmt->close();

    if (!$opportunity) {
        flash('error', 'Opportunity not found.');
        header("Location: dashboard_org.php");
        exit;
    }

    /* =========================
        OPPORTUNITY STATE
    ========================= */
    $oppState = resolveOpportunityState($opportunity);

    /* =========================
        GRACE PERIOD
    ========================= */

    if ($opportunity['status'] === 'completed') {
        notifyAttendanceGraceCountdown($opportunity, $org_id);
    }

    $attendance_grace_hours = 48;

    $event_end_ts = strtotime($opportunity['end_date'] ?: $opportunity['start_date']);
    $now_ts = time();

    $attendance_lock_ts = $event_end_ts + ($attendance_grace_hours * 3600);
    $attendance_locked = ($now_ts > $attendance_lock_ts);

    $remaining_seconds = max(0, $attendance_lock_ts - time());
    $remaining_hours   = floor($remaining_seconds / 3600);
    $remaining_minutes = floor(($remaining_seconds % 3600) / 60);


    // unpack
    $is_ongoing           = $oppState['is_ongoing'];
    $is_closed            = $oppState['is_closed'];
    $is_completed         = $oppState['is_completed'];
    $is_time_flexible     = $oppState['is_time_flexible'];
    $is_ongoing_effective = $oppState['is_ongoing_effective'];
    $read_only = $attendance_locked;

    $has_no_accepted = ($stats['total_accepted'] === 0);

    $should_warn_no_volunteers =
        $is_closed
        && $has_no_accepted
        && !$is_completed
        && !$is_ongoing
        && !$is_time_flexible;

    // display
    $opportunity['formatted_start'] = $oppState['formatted_start'];
    $opportunity['formatted_end']   = $oppState['formatted_end'];
    $opportunity['time_range']      = $oppState['time_range'];
    $opportunity['total_hours_possible'] = $oppState['total_hours_possible'];

    // Attendance can only be managed during these windows:
    // 1. Ongoing opportunities (not yet completed)
    // 2. Flexible-time opportunities (no fixed end date)
    // 3. Completed opportunities WITHIN grace period (not locked)
    $can_manage_attendance_globally = 
        ($is_ongoing && !$attendance_locked) || 
        ($is_time_flexible && !$attendance_locked) || 
        ($is_completed && !$attendance_locked);

    if ($opportunity) {
        // Get accepted volunteers for this opportunity
        $stmt = $dbc->prepare("
            SELECT 
                v.*,
                a.application_id,
                TIMESTAMPDIFF(YEAR, v.birthdate, CURDATE()) AS age,
                
                p.participation_id,
                COALESCE(p.status, 'pending') AS participation_status,
                p.hours_worked,
                p.reason AS participation_reason,
                p.feedback,
                p.participated_at,

                r.review_id,
                r.rating AS review_rating,
                r.review_text

            FROM applications a
            JOIN volunteers v 
                ON a.volunteer_id = v.vol_id

            LEFT JOIN participation p 
                ON p.volunteer_id = a.volunteer_id
                AND p.opportunity_id = a.opportunity_id

            LEFT JOIN reviews r
                ON r.reviewee_type = 'volunteer'
                AND r.reviewee_id = v.vol_id
                AND r.reviewer_type = 'organization'
                AND r.reviewer_id = ?
                AND r.opportunity_id = ?

            WHERE a.opportunity_id = ?
                AND a.status = 'accepted'

            ORDER BY v.last_name, v.first_name
        ");
        $stmt->bind_param("iii", $org_id, $opportunity_id, $opportunity_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['emergency_contacts'] = json_decode($row['emergency_contacts'] ?? '{}', true);
            $accepted_volunteers[] = $row;
            
            // Initialize participation record if doesn't exist
            if (!$row['participation_id']) {
                $row['participation_status'] = 'pending';
                $row['hours_worked'] = null;
                $row['participation_reason'] = null;
                $row['feedback'] = null;
            }
            
            $participation_records[$row['vol_id']] = $row;
            
            // Update stats
            $stats['total_accepted']++;
            $status = $row['participation_status'] ?: 'pending';
            if (!isset($stats[$status])) { $stats[$status] = 0; }
            $stats[$status]++;
        }
        $stmt->close();

        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($attendance_locked) {
                flash('error', 'Attendance records are locked.');
                header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                exit;
            }
            
            if (in_array($action, ['update_participation', 'bulk_update', 'mark_all_attended']) && !$can_manage_attendance_globally) {
                flash('error', 'Attendance can only be managed for flexible, ongoing or completed opportunities.');
                header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                exit;
            }
            
            switch ($action) {
                case 'update_participation':
                    $volunteer_id = (int)$_POST['volunteer_id'];
                    $status = $_POST['status'];
                    
                    // Get values based on status
                    $hours_worked = null;
                    $maxHours = $opportunity['total_hours_possible'];
                    $reason = null;
                    $feedback = $_POST['feedback'] ?? null;
                    
                    // Validate and process based on status
                    $valid = true;
                    $error_msg = '';
                    
                    if ($status === 'attended') {
                        $hours_worked = isset($_POST['hours_worked']) ? (int)$_POST['hours_worked'] : null;
                        if ($hours_worked === null || $hours_worked < 0 || $hours_worked > $maxHours) {
                            $valid = false;
                            $error_msg = "Valid hours (0-" . $maxHours . ") required for attended status";
                        }
                    } elseif ($status === 'absent' || $status === 'incomplete') {
                        $reason = $_POST['reason'] ?? null;
                        if (empty($reason)) {
                            $valid = false;
                            $error_msg = "Reason required for " . $status . " status";
                        }
                        if ($status === 'incomplete') {
                            $hours_worked = isset($_POST['hours_worked']) ? (int)$_POST['hours_worked'] : null;
                            if ($hours_worked === null || $hours_worked < 0 || $hours_worked > $maxHours) {
                                $valid = false;
                                $error_msg = "Valid hours (0-" . $maxHours . ") required for incomplete status";
                            }
                        }
                    }
                    
                    if (!$valid) {
                        flash('error', $error_msg);
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit;
                    }
                    
                    // Check if participation record exists
                    $stmt = $dbc->prepare("
                        SELECT participation_id FROM participation 
                        WHERE volunteer_id = ? AND opportunity_id = ?
                    ");
                    $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
                    $stmt->execute();
                    $exists = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($exists) {
                        // Update existing
                        $stmt = $dbc->prepare("
                            UPDATE participation 
                            SET status = ?, hours_worked = ?, reason = ?, feedback = ?, participated_at = NOW()
                            WHERE volunteer_id = ? AND opportunity_id = ?
                        ");
                        $stmt->bind_param("sissii", $status, $hours_worked, $reason, $feedback, 
                                        $volunteer_id, $opportunity_id);
                    } else {
                        // Insert new
                        $stmt = $dbc->prepare("
                            INSERT INTO participation 
                            (volunteer_id, opportunity_id, status, hours_worked, reason, feedback)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("iissis", $volunteer_id, $opportunity_id, $status, 
                                        $hours_worked, $reason, $feedback);
                    }
                    
                    if ($stmt->execute()) {
                        notifyVolunteerParticipation(
                            $volunteer_id,
                            $opportunity_id,
                            $status,
                            $hours_worked
                        );

                        flash('success', 'Volunteer status updated to ' . $status);
                    } else {
                        flash('error', 'Failed to update participation: ' . $stmt->error);
                    }
                    $stmt->close();
                    
                    header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                    exit();
                    
                case 'bulk_update':
                    $selected_ids = $_POST['selected_ids'] ?? [];
                    $bulk_status = $_POST['bulk_status'] ?? '';
                    $bulk_hours = isset($_POST['bulk_hours']) ? (int)$_POST['bulk_hours'] : null;
                    $bulk_reason = $_POST['bulk_reason'] ?? 'bulk_update';
                    
                    if (empty($selected_ids)) {
                        flash('error', "No volunteers selected");
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit();
                    }
                    
                    if (empty($bulk_status)) {
                        flash('error', "No action selected");
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit();
                    }

                    $maxHours = $opportunity['total_hours_possible'];
                    
                    // Validate bulk data
                    if ($bulk_status === 'attended' && ($bulk_hours === null || $bulk_hours < 0 || $bulk_hours > $maxHours)) {
                        flash('error', "Valid hours (0-24) required for marking as attended");
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit();
                    }
                    
                    if (($bulk_status === 'absent' || $bulk_status === 'incomplete') && empty($bulk_reason)) {
                        flash('error', "Reason required for " . $bulk_status . " status");
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit();
                    }
                    
                    $success_count = 0;
                    $error_count = 0;
                    
                    foreach ($selected_ids as $volunteer_id) {
                        $volunteer_id = (int)$volunteer_id;
                        
                        // Prepare values based on status
                        $hours = $bulk_status === 'attended' ? $bulk_hours : ($bulk_status === 'incomplete' ? $bulk_hours : null);
                        $reason = $bulk_status === 'attended' ? null : $bulk_reason;
                        
                        // Check if record exists
                        $stmt = $dbc->prepare("
                            SELECT participation_id FROM participation 
                            WHERE volunteer_id = ? AND opportunity_id = ?
                        ");
                        $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
                        $stmt->execute();
                        $exists = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        
                        if ($exists) {
                            $stmt = $dbc->prepare("
                                UPDATE participation 
                                SET status = ?, hours_worked = ?, reason = ?, participated_at = NOW()
                                WHERE volunteer_id = ? AND opportunity_id = ?
                            ");
                            $stmt->bind_param("sissi", $bulk_status, $hours, $reason, $volunteer_id, $opportunity_id);
                        } else {
                            $stmt = $dbc->prepare("
                                INSERT INTO participation 
                                (volunteer_id, opportunity_id, status, hours_worked, reason)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->bind_param("iisis", $volunteer_id, $opportunity_id, $bulk_status, $hours, $reason);
                        }
                        
                        if ($stmt->execute()) {
                            $success_count++;

                            notifyVolunteerParticipation(
                                $volunteer_id,
                                $opportunity_id,
                                $bulk_status,
                                $hours
                            );
                        } else {
                            $error_count++;
                        }
                        $stmt->close();
                    }
                    
                    if ($error_count > 0) {
                        flash('warning', "Updated {$success_count} volunteer(s), {$error_count} failed");
                    } else {
                        flash('success', "Successfully updated {$success_count} volunteer(s)");
                    }
                    header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                    exit();
                
                case 'submit_rating':
                case 'update_rating':

                    $stmt = $dbc->prepare("
                        SELECT rating, review_text
                        FROM reviews
                        WHERE reviewer_type = 'organization'
                        AND reviewer_id = ?
                        AND opportunity_id = ?
                        AND reviewee_type = 'volunteer'
                        AND reviewee_id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("iii", $org_id, $opportunity_id, $volunteer_id);
                    $stmt->execute();
                    $existing_review = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $is_update = (bool)$existing_review;


                    $volunteer_id = (int)$_POST['volunteer_id'];
                    $rating       = (int)$_POST['rating'];
                    $review_text  = trim($_POST['review_text'] ?? '');

                    if ($rating < 1 || $rating > 5) {
                        flash('error', 'Rating must be between 1 and 5');
                        break;
                    }

                    // Verify volunteer participated
                    $stmt = $dbc->prepare("
                        SELECT status FROM participation
                        WHERE volunteer_id = ? AND opportunity_id = ?
                        AND status IN ('attended','incomplete')
                    ");
                    $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
                    $stmt->execute();
                    if (!$stmt->get_result()->fetch_assoc()) {
                        flash('error', 'Volunteer is not eligible for rating.');
                        break;
                    }
                    $stmt->close();

                    // Insert or update
                    $stmt = $dbc->prepare("
                        INSERT INTO reviews
                            (reviewer_type, reviewer_id, opportunity_id,
                            reviewee_type, reviewee_id, rating, review_text)
                        VALUES
                            ('organization', ?, ?, 'volunteer', ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            rating = VALUES(rating),
                            review_text = VALUES(review_text)
                    ");
                    $stmt->bind_param(
                        "iiiis",
                        $org_id,
                        $opportunity_id,
                        $volunteer_id,
                        $rating,
                        $review_text
                    );

                    if ($stmt->execute()) {

                        $title = $is_update
                            ? 'Your Rating Was Updated'
                            : 'You Received a Rating';

                        $message = $is_update
                            ? "Your rating for this opportunity has been updated to {$rating} star(s)."
                            : "You received a {$rating}-star rating for your participation.";

                        if (!($is_update && (int)$existing_review['rating'] === $rating)) {
                            createNotification([
                                'user_id'       => $volunteer_id,
                                'role_target'   => 'volunteer',
                                'title'         => $title,
                                'message'       => $message,
                                'type'          => 'success',
                                'action_url'    => "/volcon/app/my_applications.php",
                                'context_type'  => 'review',
                                'context_id'    => $opportunity_id,
                                'created_by'    => 'organization',
                                'created_by_id' => $org_id
                            ]);
                        }

                        flash('success', 'Rating saved successfully');
                    } else {
                        flash('error', 'Failed to save rating');
                    }
                    $stmt->close();

                    header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                    exit();

                    
                case 'mark_all_attended':
                    $maxHours = $opportunity['total_hours_possible'];
                    $default_hours = isset($_POST['default_hours']) ? (int)$_POST['default_hours'] : 4;
                    
                    if ($default_hours < 0 || $default_hours > $maxHours) {
                        flash('error', "Hours must be between 0-$maxHours");
                        header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                        exit();
                    }

                    $updated_count = 0;
                    
                    foreach ($accepted_volunteers as $volunteer) {

                        $pstatus = $volunteer['participation_status'] ?? 'pending';

                        // Can this volunteer's attendance be modified?
                        $can_modify_participation =
                            $can_manage_attendance_globally
                            && in_array($pstatus, ['pending','attended','absent','incomplete']);

                        // Can hours be edited?
                        $can_edit_hours =
                            $can_manage_attendance_globally
                            && in_array($pstatus, ['attended','incomplete']);

                        // Can reason be edited?
                        $can_edit_reason =
                            $can_manage_attendance_globally
                            && in_array($pstatus, ['absent', 'incomplete']);

                            
                        if (($volunteer['participation_status'] ?? 'pending') === 'pending') {
                            // Check if record exists
                            $stmt = $dbc->prepare("
                                SELECT participation_id FROM participation 
                                WHERE volunteer_id = ? AND opportunity_id = ?
                            ");
                            $stmt->bind_param("ii", $volunteer['vol_id'], $opportunity_id);
                            $stmt->execute();
                            $exists = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            
                            if ($exists) {
                                $stmt = $dbc->prepare("
                                    UPDATE participation 
                                    SET status = 'attended', hours_worked = ?, participated_at = NOW()
                                    WHERE volunteer_id = ? AND opportunity_id = ?
                                ");
                                $stmt->bind_param("iii", $default_hours, $volunteer['vol_id'], $opportunity_id);
                            } else {
                                $stmt = $dbc->prepare("
                                    INSERT INTO participation 
                                    (volunteer_id, opportunity_id, status, hours_worked)
                                    VALUES (?, ?, 'attended', ?)
                                ");
                                $stmt->bind_param("iii", $volunteer['vol_id'], $opportunity_id, $default_hours);
                            }
                            
                            if ($stmt->execute()) {
                                $updated_count++;

                                notifyVolunteerParticipation(
                                    $volunteer['vol_id'],
                                    $opportunity_id,
                                    'attended',
                                    $default_hours
                                );
                            }
                            $stmt->close();
                        }
                    }
                    
                    flash('success', "Marked {$updated_count} pending volunteers as attended");
                    header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                    exit();
                    
                case 'update_feedback':
                    $volunteer_id = (int)$_POST['volunteer_id'];
                    $feedback = trim($_POST['feedback'] ?? '');
                    
                    // Clean the feedback (no URL encoding needed)
                    $feedback = htmlspecialchars_decode($feedback, ENT_QUOTES);
                    
                    // Check if participation record exists
                    $stmt = $dbc->prepare("
                        SELECT participation_id FROM participation 
                        WHERE volunteer_id = ? AND opportunity_id = ?
                    ");
                    $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
                    $stmt->execute();
                    $exists = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($exists) {
                        // Update existing
                        $stmt = $dbc->prepare("
                            UPDATE participation 
                            SET feedback = ?
                            WHERE volunteer_id = ? AND opportunity_id = ?
                        ");
                        $stmt->bind_param("sii", $feedback, $volunteer_id, $opportunity_id);
                    } else {
                        // Insert new with default status
                        $stmt = $dbc->prepare("
                            INSERT INTO participation 
                            (volunteer_id, opportunity_id, feedback, status)
                            VALUES (?, ?, ?, 'pending')
                        ");
                        $stmt->bind_param("iis", $volunteer_id, $opportunity_id, $feedback);
                    }
                    
                    if ($stmt->execute()) {
                        flash('success', 'Feedback saved successfully');
                    } else {
                        flash('error', "Failed to save feedback: " . $stmt->error);
                    }
                    $stmt->close();
                    
                    header("Location: participation_manager.php?id={$opportunity_id}&tab=attendance");
                    exit();
                    break;
            }
        }
    }
}

$page_title = "Participaction Manager";
require_once __DIR__ . '/views/layout/header.php';

?>


<link rel="stylesheet" href="/volcon/assets/css/applicants_manager.css">
<link rel="stylesheet" href="/volcon/assets/css/participation_manager.css">

<div class="vc-applicants-container">
    <!-- Header with Breadcrumb -->
    <div class="vc-page-header">
        <div>
            <a href="dashboard_org.php" class="vc-btn vc-btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <?php if ($opportunity): ?>
            <a href="applicants_manager.php?id=<?= $opportunity_id ?>" class="vc-btn vc-btn-secondary">
                <i class="fas fa-clipboard-list"></i> View Applicants
            </a>
            <?php endif; ?>
        </div>
        <div>
            <h1>Participation Manager</h1>
            <?php if ($opportunity): ?>
            <p class="vc-subtitle">
                Managing: <strong><?= htmlspecialchars($opportunity['title']) ?></strong>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$opportunity): ?>
        <!-- No opportunity selected -->
        <div class="vc-empty-state">
            <div class="vc-empty-icon">
                <i class="fas fa-folder-open"></i>
            </div>

            <h3>No Opportunity Selected</h3>
            <p>Please select an opportunity from your dashboard or applicants manager.</p>

            <a href="dashboard_org.php" class="vc-btn vc-btn-primary" style="margin-top: 24px">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
        </div>

    
    <?php else: ?>
        <!-- Opportunity Header Card -->
        <div class="vc-opportunity-header-card">
            <div class="vc-header-main">
                <div>
                    <h2><a href="view_opportunity.php?id=<?= htmlspecialchars($opportunity['opportunity_id']) ?>" title="View Opportunity"><?= htmlspecialchars($opportunity['title']) ?></a></h2>
                    <div class="vc-opp-meta-large">
                        <span class="vc-badge vc-status-<?= $opportunity['status'] ?>">
                            <?= ucfirst($opportunity['status']) ?>
                        </span>
                        <span>
                            <i class="fas fa-calendar"></i> 
                            <?= $opportunity['formatted_start'] ?>
                            <?php if ($opportunity['end_date'] && $opportunity['end_date'] != $opportunity['start_date']): ?>
                                - <?= $opportunity['formatted_end'] ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($opportunity['time_range']): ?>
                        <span><i class="fas fa-clock"></i> <?= $opportunity['time_range'] ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-map-marker-alt"></i> 
                            <?= htmlspecialchars($opportunity['city'] . ', ' . $opportunity['state']) ?>
                        </span>
                    </div>
                </div>
                <div class="vc-slots-summary">
                    <div class="vc-slot-count">
                        <span class="vc-count"><?= $stats['total_accepted'] ?></span>
                        <span class="vc-label">Accepted</span>
                    </div>
                    <?php if ($opportunity['number_of_volunteers']): ?>
                    <div class="vc-slot-total">
                        <span class="vc-count">/ <?= $opportunity['number_of_volunteers'] ?></span>
                        <span class="vc-label">Slots</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Status Warning Banner -->
             <?php if ($should_warn_no_volunteers): ?>
            <div class="vc-status-banner vc-banner-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>No volunteers accepted.</strong>
                <span class="vc-banner-hint">
                    This opportunity is closed but has zero accepted volunteers. Reopen applications to accept volunteers, otherwise this opportunity will be automatically canceled when it starts.
                </span>

                <div class="vc-banner-actions">
                    <a href="change_status.php?id=<?= $opportunity_id ?>&action=reopen"
                    class="vc-btn vc-btn-sm vc-btn-warning"
                    onclick="return confirm('Reopen this opportunity to accept volunteers?')">
                        <i class="fas fa-lock-open"></i> Reopen Applications
                    </a>
                </div>
            </div>
            <?php elseif ($is_ongoing): ?>
                <div class="vc-status-banner vc-banner-info">
                    <i class="fas fa-hourglass-half"></i>
                    <strong>Attendance marking in progress</strong> - You can update participation records until the opportunity is completed.
                </div>

            <?php elseif ($is_completed && !$attendance_locked): ?>
                <?php
                    if ($remaining_hours <= 6) {
                        $banner_class = 'vc-banner-danger';
                        $icon = 'fa-exclamation-triangle';
                    } elseif ($remaining_hours <= 24) {
                        $banner_class = 'vc-banner-warning';
                        $icon = 'fa-hourglass-half';
                    } else {
                        $banner_class = 'vc-banner-info';
                        $icon = 'fa-info-circle';
                    }
                ?>
                <div class="vc-status-banner <?= $banner_class ?>" id="attendanceCountdown"
                    data-seconds="<?= $remaining_seconds ?>">
                    <i class="fas <?= $icon ?>"></i>
                    <strong>Attendance Lock Countdown:</strong>
                    <span id="countdownText">
                        <?= $remaining_hours ?>h <?= $remaining_minutes ?>m remaining
                    </span>
                </div>

            <?php elseif ($is_completed && $attendance_locked): ?>
                <div class="vc-status-banner vc-banner-warning">
                    <i class="fas fa-lock"></i>
                    <strong>Attendance finalized</strong> - Records are locked. Contact admin for changes.
                </div>

            <?php elseif ($is_closed): ?>
                <div class="vc-status-banner vc-banner-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Event not started</strong> - Attendance marking will be available when the opportunity becomes ongoing.
                </div>
            <?php endif; ?>
        </div>

        <!-- Statistics Cards -->
        <div class="vc-stats-grid">
            <div class="vc-stat-card">
                <div class="vc-stat-value"><?= $stats['total_accepted'] ?></div>
                <div class="vc-stat-label">Total Volunteers</div>
            </div>
            <div class="vc-stat-card vc-stat-pending">
                <div class="vc-stat-value"><?= $stats['pending'] ?></div>
                <div class="vc-stat-label">Pending</div>
            </div>
            <div class="vc-stat-card vc-stat-attended">
                <div class="vc-stat-value"><?= $stats['attended'] ?></div>
                <div class="vc-stat-label">Attended</div>
            </div>
            <div class="vc-stat-card vc-stat-absent">
                <div class="vc-stat-value"><?= $stats['absent'] + $stats['incomplete'] ?></div>
                <div class="vc-stat-label">Absent/Incomplete</div>
            </div>
        </div>

        <!-- Control Bar (Status-aware) -->
        <div class="vc-control-bar">
            <?php if ($is_closed): ?>
                <div class="vc-control-info">
                    <i class="fas fa-info-circle"></i>
                    This opportunity has not started yet.
                </div>
                <div class="vc-control-actions">
                    <button class="vc-btn vc-btn-secondary" onclick="exportAttendanceList()">
                        <i class="fas fa-file-export"></i> Export List
                    </button>
                </div>
                
            <?php elseif ($is_ongoing): ?>
                <div class="vc-control-info">
                    <i class="fas fa-check-circle"></i>
                    Attendance can be edited until opportunity is completed.
                </div>
                <div class="vc-control-actions">
                    <button class="vc-btn vc-btn-primary" onclick="showAttendanceModal()">
                        <i class="fas fa-user-check"></i> Mark Attendance
                    </button>
                    <button class="vc-btn vc-btn-success" onclick="quickMarkAllAttended()">
                        <i class="fas fa-bolt"></i> Quick Mark All
                    </button>
                </div>
                
            <?php elseif ($is_completed): ?>
                <div class="vc-control-info">
                    <i class="fas fa-lock"></i>
                    This opportunity is completed. Records are locked.
                </div>
                <div class="vc-control-actions">
                    <button class="vc-btn vc-btn-secondary" onclick="viewFinalReport()">
                        <i class="fas fa-chart-bar"></i> View Report
                    </button>
                    <button class="vc-btn vc-btn-success" onclick="generateCertificates()">
                        <i class="fas fa-certificate"></i> Generate Certificates
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabs Navigation -->
        <div class="vc-tabs">
            <a href="?id=<?= $opportunity_id ?>&tab=summary" 
                class="vc-tab <?= $current_tab === 'summary' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i> Summary
            </a>
            <a href="?id=<?= $opportunity_id ?>&tab=attendance" 
                class="vc-tab <?= $current_tab === 'attendance' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i> Attendance
                <?php if ($stats['pending'] > 0): ?>
                <span class="vc-tab-badge"><?= $stats['pending'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $opportunity_id ?>&tab=export" 
                class="vc-tab <?= $current_tab === 'export' ? 'active' : '' ?>">
                <i class="fas fa-file-export"></i> Export
            </a>
        </div>

        <!-- Tab Content -->
        <div class="vc-tab-content">
            <?php if ($current_tab === 'summary'): ?>
                <!-- Summary Tab -->
                <div class="vc-summary-grid">
                    <div class="vc-summary-card">
                        <h3><i class="fas fa-users"></i> Participation Overview</h3>
                        <div class="vc-participation-chart">
                            <canvas id="participationChart"></canvas>
                        </div>
                        <div class="vc-chart-legend">
                            <span class="vc-legend-item vc-legend-attended">
                                <i class="fas fa-square"></i> Attended (<?= $stats['attended'] ?>)
                            </span>
                            <span class="vc-legend-item vc-legend-pending">
                                <i class="fas fa-square"></i> Pending (<?= $stats['pending'] ?>)
                            </span>
                            <span class="vc-legend-item vc-legend-absent">
                                <i class="fas fa-square"></i> Absent (<?= $stats['absent'] ?>)
                            </span>
                            <span class="vc-legend-item vc-legend-incomplete">
                                <i class="fas fa-square"></i> Incomplete (<?= $stats['incomplete'] ?>)
                            </span>
                        </div>
                    </div>
                    
                    <div class="vc-summary-card">
                        <h3><i class="fas fa-clock"></i> Hours Summary</h3>
                        <div class="vc-hours-stats">
                            <div class="vc-hour-stat">
                                <span class="vc-hour-value">
                                    <?= array_sum(array_column($participation_records, 'hours_worked')) ?>
                                </span>
                                <span class="vc-hour-label">Total Hours</span>
                            </div>
                            <div class="vc-hour-stat">
                                <span class="vc-hour-value">
                                    <?= $stats['attended'] > 0 ? 
                                        round(array_sum(array_column($participation_records, 'hours_worked')) / $stats['attended'], 1) : 0 ?>
                                </span>
                                <span class="vc-hour-label">Avg per Volunteer</span>
                            </div>
                            <div class="vc-hour-stat">
                                <span class="vc-hour-value">
                                    <?= $opportunity['total_hours_possible'] ?>
                                </span>
                                <span class="vc-hour-label">Max Possible</span>
                            </div>
                        </div>
                        
                        <h4 class="mt-4">Top Contributors</h4>
                        <div class="vc-top-contributors">
                            <?php 
                            usort($participation_records, function($a, $b) {
                                return ($b['hours_worked'] ?? 0) <=> ($a['hours_worked'] ?? 0);
                            });
                            $top_contributors = array_slice($participation_records, 0, 3);
                            ?>
                            <?php foreach ($top_contributors as $volunteer): ?>
                                <?php if ($volunteer['hours_worked'] > 0): ?>
                                <div class="vc-contributor">
                                    <?php if (!empty($volunteer['profile_picture'])): ?>
                                    <img 
                                        src="<?= esc($volunteer['profile_picture']) ?>" 
                                        alt="<?= esc($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>" 
                                        class="vc-avatar-img"
                                    >
                                    <?php else: ?>
                                    <div class="vc-avatar-fl">
                                        <?= strtoupper(
                                            mb_substr($volunteer['first_name'], 0, 1) . 
                                            mb_substr($volunteer['last_name'], 0, 1)
                                        ) ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="vc-contributor-info">
                                        <div class="vc-contributor-name">
                                            <?= htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>
                                        </div>
                                        <div class="vc-contributor-hours">
                                            <?= $volunteer['hours_worked'] ?> hours
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_tab === 'attendance'): ?>
                <!-- Attendance Tab -->
                <?php if ($is_closed && !$is_ongoing && !$is_time_flexible): ?>
                    <div class="vc-empty-state">
                        <div class="vc-empty-icon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <h3>Attendance Not Available Yet</h3>
                        <p>This opportunity has not started. Attendance tracking will be available when the opportunity status becomes "ongoing".</p>
                        <div class="vc-info-box mt-3">
                            <strong>Current Status:</strong> <?= ucfirst($opportunity['status']) ?><br>
                            <?php if ($opportunity['formatted_start']): ?>
                            <strong>Start Date:</strong> <?= $opportunity['formatted_start'] ?>
                            <?php endif; ?>
                        </div>
                    </div>
                
                <?php else: ?>
                    <!-- Bulk Actions Bar -->
                    <div class="vc-bulk-actions" id="bulkActionsBar" style="display:none;">
                        <div class="vc-bulk-selected">
                            <span id="selectedCount">0</span> volunteer(s) selected
                        </div>
                        <form method="post" id="bulkActionForm" class="vc-bulk-form" onsubmit="return confirmBulkAction()">
                            <input type="hidden" name="action" value="bulk_update">
                            <div id="selectedIdsContainer"></div>
                            
                            <select name="bulk_status" required onchange="toggleBulkFields(this)">
                                <option value="">Choose Status...</option>
                                <option value="attended">Mark as Attended</option>
                                <option value="absent">Mark as Absent</option>
                                <option value="incomplete">Mark as Incomplete</option>
                            </select>
                            
                            <div id="bulkHoursContainer" style="display:none;" class="bulk-field">
                                <input type="number" name="bulk_hours" min="0" max="<?= (int)$opportunity['total_hours_possible'] ?>" 
                                    placeholder="Hours" class="vc-input-small">
                            </div>
                            
                            <div id="bulkReasonContainer" style="display:none;" class="bulk-field">
                                <select name="bulk_reason" class="vc-select-small">
                                    <option value="sick">Sick</option>
                                    <option value="accident">Accident</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="family">Family</option>
                                    <option value="transportation">Transportation</option>
                                    <option value="weather">Weather</option>
                                    <option value="left_early">Left Early</option>
                                    <option value="personal">Personal</option>
                                    <option value="other" selected>Other</option>
                                </select>
                            </div>
                            
                            <button type="submit"
                                class="vc-btn vc-btn-primary"
                                <?= $attendance_locked ? 'disabled' : '' ?>>
                                Apply to Selected
                            </button>

                            <button type="button" class="vc-btn vc-btn-secondary" onclick="clearSelection()">
                                Clear Selection
                            </button>
                        </form>
                    </div>

                    <!-- Attendance Table -->
                    <div class="vc-attendance-table-container">
                        <div class="vc-table-wrapper">
                            <!-- Table Header -->
                            <div class="vc-table-header">
                                <?php if ($can_manage_attendance_globally): ?>
                                <div class="th th-checkbox" style="width: 40px;">
                                    <input type="checkbox" class="select-all" onclick="toggleAllSelection(this)">
                                </div>
                                <?php else: ?>
                                <div class="th th-checkbox" style="width: 40px;">
                                    <input type="checkbox" class="select-all" disabled>
                                </div> 
                                <?php endif; ?>
                                <div class="th th-volunteer">Volunteer</div>
                                <div class="th th-status">Status</div>
                                <div class="th th-hours">Hours</div>
                                <div class="th th-reason">Reason</div>
                                <div class="th th-feedback">Remark</div>
                                <div class="th th-actions">Actions</div>
                            </div>
                            
                            <!-- Table Body -->
                            <div class="vc-table-body">
                                <?php foreach ($accepted_volunteers as $volunteer): 
                                    $pstatus = $volunteer['participation_status'] ?? 'pending';

                                    $can_modify_participation =
                                        $can_manage_attendance_globally
                                        && in_array($pstatus, ['pending','attended','absent','incomplete']);

                                    $can_edit_hours =
                                        $can_manage_attendance_globally
                                        && in_array($pstatus, ['attended','incomplete']);

                                    $can_edit_reason =
                                        $can_manage_attendance_globally
                                        && in_array($pstatus, ['absent','incomplete']);

                                ?>
                                <div class="vc-table-row" data-vol-id="<?= $volunteer['vol_id'] ?>">
                                    <!-- Checkbox -->
                                    <?php if ($can_manage_attendance_globally): ?>
                                    <div class="td td-checkbox" data-label="">
                                        <input type="checkbox" class="volunteer-checkbox" 
                                            value="<?= $volunteer['vol_id'] ?>"
                                            onchange="updateBulkActions()"
                                            <?= ($can_manage_attendance_globally && ($pstatus === 'pending' || $pstatus === 'incomplete')) ? '' : 'disabled' ?>
                                            <?= ($pstatus === 'pending' || $pstatus === 'incomplete') ? '' : 'style="opacity: 0.5"' ?>>
                                    </div>
                                    <?php else: ?>
                                    <div class="th td-checkbox" data-label="">
                                        <input type="checkbox" class="volunteer-checkbox" disabled>
                                    </div> 
                                    <?php endif; ?>
                                    
                                    <!-- Volunteer Info -->
                                    <div class="td td-volunteer" data-label="Volunteer">
                                        <div class="vc-volunteer-info">
                                            <?php if (!empty($volunteer['profile_picture'])): ?>
                                            <img src="<?= esc($volunteer['profile_picture']) ?>" 
                                                alt="<?= esc($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>" 
                                                class="vc-avatar-img">
                                            <?php else: ?>
                                            <div class="vc-avatar-fl">
                                                <?= strtoupper(
                                                    mb_substr($volunteer['first_name'], 0, 1) . 
                                                    mb_substr($volunteer['last_name'], 0, 1)
                                                ) ?>
                                            </div>
                                            <?php endif; ?>
                                            <div class="vc-volunteer-details">
                                                <div class="vc-volunteer-name">
                                                    <?= htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>
                                                    <span class="vc-volunteer-age">(<?= $volunteer['age'] ?>)</span>
                                                </div>
                                                <div class="vc-volunteer-meta">
                                                    <span class="vc-meta-item">
                                                        <i class="fas fa-phone"></i> <?= htmlspecialchars($volunteer['phone_no']) ?>
                                                    </span>
                                                    <span class="vc-meta-item">
                                                        <i class="fas fa-map-marker-alt"></i> 
                                                        <?= htmlspecialchars($volunteer['city'] . ', ' . $volunteer['state']) ?>
                                                    </span>
                                                    <?php if ($volunteer['emergency_contacts']): ?>
                                                    <span class="vc-meta-item vc-emergency-info" 
                                                        data-contacts='<?= json_encode($volunteer['emergency_contacts']) ?>'
                                                        onclick="showEmergencyContacts(this)">
                                                        <i class="fas fa-exclamation-triangle"></i> Emergency
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Status -->
                                    <div class="td td-status" data-label="Status">
                                        <span class="vc-badge vc-badge-<?= $pstatus ?>">
                                            <?= ucfirst($pstatus) ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Hours -->
                                    <div class="td td-hours" data-label="Hours">
                                        <span class="vc-hours-display">
                                            <?= $volunteer['hours_worked'] ?? '-' ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Reason -->
                                    <div class="td td-reason" data-label="Reason">
                                        <span class="vc-reason-display">
                                            <?= $volunteer['participation_reason'] ? ucfirst(str_replace('_', ' ', $volunteer['participation_reason'])) : '-' ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Feedback -->
                                    <div class="td td-feedback" data-label="Feedback" id="feedback-cell-<?= $volunteer['vol_id'] ?>">
                                        <?php 
                                        $feedback = $volunteer['feedback'] ?? '';
                                        $has_feedback = !empty($feedback);
                                        $decoded_feedback = strip_tags($feedback, '<p><br><strong><em><ul><ol><li>');;
                                        ?>
                                        
                                        <!-- Display mode (default) -->
                                        <div class="vc-feedback-display <?= $has_feedback ? 'vc-feedback-has-content' : 'empty' ?>" 
                                            onclick="enableFeedbackEdit(<?= $volunteer['vol_id'] ?>)">
                                            <?php if ($has_feedback): ?>
                                                <?= htmlspecialchars($decoded_feedback, ENT_QUOTES, 'UTF-8') ?>
                                            <?php else: ?>
                                                Click to add remark...
                                            <?php endif; ?>
                                            <span class="vc-edit-icon">
                                                <i class="fas fa-edit"></i>
                                            </span>
                                        </div>
                                        
                                        <!-- Edit mode (hidden by default) -->
                                        <div class="vc-feedback-edit" id="feedback-edit-<?= $volunteer['vol_id'] ?>">
                                            <form method="post" class="vc-feedback-form" 
                                                onsubmit="return validateFeedbackForm(this, <?= $volunteer['vol_id'] ?>)">
                                                <input type="hidden" name="action" value="update_feedback">
                                                <input type="hidden" name="volunteer_id" value="<?= $volunteer['vol_id'] ?>">
                                                
                                                <textarea class="vc-feedback-textarea" 
                                                        name="feedback"
                                                        placeholder="Enter feedback for this volunteer..."
                                                        rows="3"><?= htmlspecialchars($decoded_feedback, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                
                                                <div class="vc-feedback-actions">
                                                    <button type="button" class="vc-btn vc-btn-sm vc-btn-secondary" 
                                                            onclick="cancelFeedbackEdit(<?= $volunteer['vol_id'] ?>)">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="vc-btn vc-btn-sm vc-btn-primary">
                                                        <i class="fas fa-save"></i> Save
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="td td-actions" data-label="Actions">
                                        <div class="vc-action-buttons">
                                            <?php if ($can_manage_attendance_globally): ?>
                                                <?php if ($pstatus === 'pending'): ?>
                                                    <!-- Pending: Can mark attendance -->
                                                    <button type="button" class="vc-btn vc-btn-sm vc-btn-success"
                                                            onclick="showAttendModal(<?= $volunteer['vol_id'] ?>, <?= $opportunity['total_hours_possible'] ?>,
                                                            '<?= esc($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>'
                                                            )">
                                                        <i class="fas fa-check"></i> Attend
                                                    </button>
                                                    <button type="button" class="vc-btn vc-btn-sm vc-btn-danger"
                                                            onclick="showAbsentModal(
                                                            <?= $volunteer['vol_id'] ?>,
                                                            '<?= esc($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>'
                                                            )">
                                                        <i class="fas fa-times"></i> Absent
                                                    </button>
                                                    <button type="button" 
                                                        class="vc-btn vc-btn-sm vc-btn-warning"
                                                        onclick="showIncompleteModal(<?= $volunteer['vol_id'] ?>,
                                                        <?= $opportunity['total_hours_possible'] ?>,
                                                        '<?= esc($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?>'
                                                        )">
                                                        <i class="fas fa-hourglass-half"></i> Incomplete
                                                    </button>

                                                    
                                                <?php elseif (in_array($pstatus, ['attended', 'incomplete', 'absent'])): ?>
                                                    <button type="button"
                                                        class="vc-btn vc-btn-sm vc-btn-outline"
                                                        onclick="editAttendance(
                                                            <?= $volunteer['vol_id'] ?>,
                                                            '<?= $pstatus ?>',
                                                            <?= (int)($volunteer['hours_worked'] ?? 0) ?>,
                                                            '<?= $volunteer['participation_reason'] ?? '' ?>',
                                                            <?= (int)$opportunity['total_hours_possible'] ?>,
                                                            '<?= esc($volunteer['first_name'].' '.$volunteer['last_name']) ?>'
                                                        )">
                                                        <i class="fas fa-edit"></i> Edit Attendance
                                                    </button>

                                                    <?php if (empty($volunteer['review_id'])): ?>
                                                        <!-- Attended / Not Rated -->
                                                        <button type="button"
                                                            class="vc-btn vc-btn-sm vc-btn-primary"
                                                            onclick="showRateModal(
                                                                <?= $volunteer['vol_id'] ?>,
                                                                '<?= esc($volunteer['first_name'].' '.$volunteer['last_name']) ?>'
                                                            )">
                                                            <i class="fas fa-star"></i> Rate
                                                        </button>

                                                    <?php else: ?>
                                                        <!-- Attended / Rated -->
                                                        <button type="button"
                                                            class="vc-btn vc-btn-sm vc-btn-warning"
                                                            onclick="showEditRateModal(
                                                                <?= $volunteer['vol_id'] ?>,
                                                                '<?= esc($volunteer['first_name'].' '.$volunteer['last_name']) ?>',
                                                                <?= (int)$volunteer['review_rating'] ?>,
                                                                '<?= esc($volunteer['review_text'] ?? '') ?>'
                                                            )">
                                                            <i class="fas fa-edit"></i> Edit Rate
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Always available -->
                                            <button 
                                                class="vc-btn vc-btn-sm vc-btn-outline"
                                                onclick="startChatWith(
                                                    <?= (int)$volunteer['vol_id'] ?>,
                                                    'vol',
                                                    '<?= esc($volunteer['first_name'].' '.$volunteer['last_name']) ?>',
                                                    '<?= esc($volunteer['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png') ?>'
                                                )">
                                                <i class="fas fa-comment"></i> Send Message
                                            </button>
                                            <a href="profile_vol.php?id=<?= $volunteer['vol_id'] ?>" 
                                            class="vc-btn vc-btn-sm vc-btn-secondary">
                                                <i class="fas fa-eye"></i> Profile
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($accepted_volunteers)): ?>
                                <div class="vc-empty-row">
                                    <div class="td" colspan="7">No accepted volunteers found for this opportunity.</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions Footer -->
                    <?php if ($can_manage_attendance_globally): ?>
                    <div class="vc-quick-actions">
                        <div class="vc-quick-info">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Quick Tip:</strong> Select multiple volunteers and use bulk actions for faster updates.
                        </div>
                        <div class="vc-quick-buttons">
                            <button class="vc-btn vc-btn-success" onclick="quickMarkAllAttended()">
                                <i class="fas fa-bolt"></i> Quick Mark All Pending as Attended
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>


            <?php elseif ($current_tab === 'export'): ?>
                <!-- Export Tab -->
                <div class="vc-export-panel">
                    <div class="vc-export-card">
                        <h3><i class="fas fa-file-excel"></i> Export Data</h3>
                        <p>Generate reports and export participation data in various formats.</p>
                        
                        <div class="vc-export-options">
                            <div class="vc-export-option">
                                <div class="vc-export-icon">
                                    <i class="fas fa-file-csv"></i>
                                </div>
                                <div class="vc-export-details">
                                    <h4>CSV Report</h4>
                                    <p>Spreadsheet format with all participation details</p>
                                </div>
                                <button class="vc-btn vc-btn-primary" 
                                        onclick="exportToCSV()">
                                    Export CSV
                                </button>
                            </div>
                            
                            <div class="vc-export-option">
                                <div class="vc-export-icon">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div class="vc-export-details">
                                    <h4>PDF Summary</h4>
                                    <p>Printable report with statistics and charts</p>
                                </div>
                                <button class="vc-btn vc-btn-danger" 
                                        onclick="exportToPDF()">
                                    Export PDF
                                </button>
                            </div>
                            
                            <div class="vc-export-option">
                                <div class="vc-export-icon">
                                    <i class="fas fa-certificate"></i>
                                </div>
                                <div class="vc-export-details">
                                    <h4>Certificates</h4>
                                    <p>Generate certificates for attended volunteers</p>
                                </div>
                                <button class="vc-btn vc-btn-success" 
                                        onclick="generateCertificates()">
                                    Generate Certificate
                                </button>
                                <!-- <form method="post">
                                    <input type="hidden" name="action" value="generate_certificates">
                                    <button type="submit" class="vc-btn vc-btn-success">
                                        Generate Certificates
                                    </button>
                                </form> -->
                            </div>
                            
                            <div class="vc-export-option">
                                <div class="vc-export-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="vc-export-details">
                                    <h4>Analytics Report</h4>
                                    <p>Detailed analytics and insights</p>
                                </div>
                                <button class="vc-btn vc-btn-warning" 
                                        onclick="generateAnalytics()">
                                    Generate Report
                                </button>
                            </div>
                        </div>
                        
                        <div class="vc-export-filters mt-4">
                            <h4><i class="fas fa-filter"></i> Filter Data</h4>
                            <div class="vc-filter-row">
                                <label>
                                    <input type="checkbox" checked> Include volunteer details
                                </label>
                                <label>
                                    <input type="checkbox" checked> Include hours worked
                                </label>
                                <label>
                                    <input type="checkbox"> Include feedback
                                </label>
                                <label>
                                    <input type="checkbox" checked> Include emergency contacts
                                </label>
                            </div>
                            <div class="vc-filter-row">
                                <select class="vc-filter-select">
                                    <option value="all">All Statuses</option>
                                    <option value="attended">Attended Only</option>
                                    <option value="absent">Absent Only</option>
                                </select>
                                <input type="date" class="vc-filter-date" placeholder="From date">
                                <input type="date" class="vc-filter-date" placeholder="To date">
                            </div>
                        </div>
                    </div>
                    
                    <div class="vc-export-preview">
                        <h3><i class="fas fa-eye"></i> Preview</h3>
                        <div class="vc-preview-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Status</th>
                                        <th>Hours</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($accepted_volunteers, 0, 5) as $volunteer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($volunteer['first_name'] . ' ' . $volunteer['last_name']) ?></td>
                                        <td><?= ucfirst($volunteer['participation_status']) ?></td>
                                        <td><?= $volunteer['hours_worked'] ?? '-' ?></td>
                                        <td><?= $volunteer['participation_reason'] ? ucfirst(str_replace('_', ' ', $volunteer['participation_reason'])) : '-' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="vc-preview-info">
                            <p><i class="fas fa-info-circle"></i> Preview shows first 5 records. Full export will include all <?= count($accepted_volunteers) ?> volunteers.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modals -->

<!-- Emergency Contacts Modal -->
<div id="emergencyModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Emergency Contacts</h3>
            <button class="vc-modal-close" onclick="closeModal('emergencyModal')">&times;</button>
        </div>
        <div class="vc-modal-body" id="emergencyContactsBody">
            <!-- Dynamic content -->
        </div>
        <div class="vc-modal-footer">
            <button class="vc-btn vc-btn-primary" onclick="closeModal('emergencyModal')">Close</button>
        </div>
    </div>
</div>

<!-- Quick Mark All Modal -->
<div id="quickMarkModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3><i class="fas fa-bolt"></i> Quick Mark All Pending as Attended</h3>
            <button class="vc-modal-close" onclick="closeModal('quickMarkModal')">&times;</button>
        </div>
        <form method="post" onsubmit="return confirm('Mark all pending volunteers as attended?')">
            <div class="vc-modal-body">
                <p>This will mark all <strong><?= $stats['pending'] ?></strong> pending volunteers as attended.</p>
                <div class="vc-modal-form">
                    <label for="defaultHours">Default Hours for All:</label>
                    <input type="number" id="defaultHours" name="default_hours" value="4" min="0" max="<?= (int)$opportunity['total_hours_possible'] ?>" required>
                    <small>Enter the default number of hours for all volunteers (0-<? (int)$opportunity['total_hours_possible'] ?>).</small>
                </div>
            </div>
            <div class="vc-modal-footer">
                <input type="hidden" name="action" value="mark_all_attended">
                <button type="button" class="vc-btn vc-btn-secondary" onclick="closeModal('quickMarkModal')">Cancel</button>
                <button type="submit" class="vc-btn vc-btn-success">
                    <i class="fas fa-check"></i> Mark All Attended
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Mark Attended Modal -->
<div id="markAttendModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-user-check"></i>
                Mark Volunteer as Attended
            </h3>
            <button class="vc-modal-close" onclick="closeModal('markAttendModal')">
                &times;
            </button>
        </div>

        <div class="vc-modal-body">
            <div class="vc-status-switch">
                <label>Change Status</label>
                <select onchange="switchEditStatus(this.value)">
                    <option value="">— Keep current —</option>
                    <option value="attended">Attended</option>
                    <option value="absent">Absent</option>
                    <option value="incomplete">Incomplete</option>
                </select>
            </div>

            <p>
                <strong>Name:</strong>
                <span id="attendVolunteerName"></span>
            </p>
           <small id="attendHoursHelp">This will mark the volunteer as <strong>attended</strong> and assign the <strong>full opportunity hours</strong>.</small>

            <input type="hidden" id="attendVolunteerId">
            <input type="hidden" id="attendMaxHours">
        </div>

        <div class="vc-modal-footer">
            <button
                type="button"
                class="vc-btn vc-btn-secondary"
                onclick="closeModal('markAttendModal')">
                Cancel
            </button>

            <button
                type="button"
                class="vc-btn vc-btn-success"
                onclick="confirmAttend()">
                <i class="fas fa-check"></i> Confirm Attendance
            </button>
        </div>
    </div>
</div>


<!-- Mark Absent Modal -->
<div id="markAbsentModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-user-times"></i>
                Mark Volunteer as Absent
            </h3>
            <button class="vc-modal-close" onclick="closeModal('markAbsentModal')">
                &times;
            </button>
        </div>

        <div class="vc-modal-body">
            <div class="vc-status-switch">
                <label>Change Status</label>
                <select onchange="switchEditStatus(this.value)">
                    <option value="">— Keep current —</option>
                    <option value="attended">Attended</option>
                    <option value="absent">Absent</option>
                    <option value="incomplete">Incomplete</option>
                </select>
            </div>
            <p>
                <strong>Name:</strong>
                <span id="absentVolunteerName"></span>
            </p>
            <p>Please select the reason for the volunteer’s absence.</p>

            <div class="vc-modal-form">
                <label for="absentReason"><b>Reason for Absence</b></label>
                <select id="absentReason" class="vc-input" required>
                    <option value="">-- Select reason --</option>
                    <option value="sick">Sick</option>
                    <option value="accident">Accident</option>
                    <option value="emergency">Emergency</option>
                    <option value="family">Family</option>
                    <option value="transportation">Transportation</option>
                    <option value="weather">Weather</option>
                    <option value="left_early">Left Early</option>
                    <option value="personal">Personal</option>
                    <option value="other">Other</option>
                </select>

                <small>Select the most appropriate reason.</small>
            </div>

            <input type="hidden" id="absentVolunteerId">
        </div>

        <div class="vc-modal-footer">
            <button
                type="button"
                class="vc-btn vc-btn-secondary"
                onclick="closeModal('markAbsentModal')">
                Cancel
            </button>

            <button
                type="button"
                class="vc-btn vc-btn-danger"
                onclick="confirmAbsent()">
                <i class="fas fa-times"></i> Mark Absent
            </button>
        </div>
    </div>
</div>


<!-- Mark Incomplete Modal -->
<div id="markIncompleteModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3>
                <i class="fas fa-user-clock"></i>
                Mark Volunteer as Incomplete
            </h3>
            <button class="vc-modal-close" onclick="closeModal('markIncompleteModal')">
                &times;
            </button>
        </div>

        <div class="vc-modal-body">
            <div class="vc-status-switch">
                <label>Change Status</label>
                <select onchange="switchEditStatus(this.value)">
                    <option value="">— Keep current —</option>
                    <option value="attended">Attended</option>
                    <option value="absent">Absent</option>
                    <option value="incomplete">Incomplete</option>
                </select>
            </div>
            <p>
                <strong>Name:</strong>
                <span id="incompleteVolunteerName"></span>
            </p>
            <p>Please provide the hours worked and the reason for leaving early.</p>

            <!-- Hours Worked -->
            <div class="vc-modal-form">
                <label for="incompleteHours">Hours Worked</label>
                <input
                    type="number"
                    id="incompleteHours"
                    class="vc-input"
                    min="0"
                    max="<?= (int)$opportunity['total_hours_possible'] ?>"
                    required
                >
                <small id="incompleteHoursHelp">Please insert between 0 - <? $maxHours ?>.</small>
            </div>

            <!-- Reason -->
            <div class="vc-modal-form">
                <label for="incompleteReason">Reason for Incomplete</label>
                <select id="incompleteReason" class="vc-input" required>
                    <option value="">-- Select reason --</option>
                    <option value="left_early">Left Early</option>
                    <option value="sick">Sick</option>
                    <option value="emergency">Emergency</option>
                    <option value="family">Family</option>
                    <option value="transportation">Transportation</option>
                    <option value="weather">Weather</option>
                    <option value="personal">Personal</option>
                    <option value="other">Other</option>
                </select>
                <small>Select the most appropriate reason.</small>
            </div>

            <input type="hidden" id="incompleteVolunteerId">
            <input type="hidden" id="incompleteMaxHours">
        </div>

        <div class="vc-modal-footer">
            <button
                type="button"
                class="vc-btn vc-btn-secondary"
                onclick="closeModal('markIncompleteModal')">
                Cancel
            </button>

            <button
                type="button"
                class="vc-btn vc-btn-warning"
                onclick="confirmIncomplete()">
                <i class="fas fa-exclamation-triangle"></i> Mark Incomplete
            </button>
        </div>
    </div>
</div>


<!-- Rate Volunteer Modal -->
<div id="rateVolunteerModal" class="vc-modal">
    <div class="vc-modal-content">
        <div class="vc-modal-header">
            <h3><i class="fas fa-star"></i> Rate Volunteer</h3>
            <button class="vc-modal-close" onclick="closeModal('rateVolunteerModal')">&times;</button>
        </div>

        <div class="vc-modal-body">
            <p>
                <strong>Name:</strong>
                <span id="rateVolunteerName"></span>
            </p>

            <!-- Rating -->
            <div class="vc-modal-form">
                <label style="text-align: center;"><strong>Give Rating</strong></label>
                <div class="vc-rating-wrapper">
                    <div class="vc-rating-stars" id="rateStars">
                        <i class="fas fa-star star" data-value="1"></i>
                        <i class="fas fa-star star" data-value="2"></i>
                        <i class="fas fa-star star" data-value="3"></i>
                        <i class="fas fa-star star" data-value="4"></i>
                        <i class="fas fa-star star" data-value="5"></i>
                    </div>
                </div>
                <input type="hidden" id="rateValue" value="0">
                <small style="text-align: center;">Select 1–5 stars</small>
            </div>

            <!-- Review -->
            <div class="vc-modal-form">
                <label><strong>Comment (optional)</strong></label>
                <textarea id="rateComment"
                          class="vc-input vc-textarea-full"
                          rows="3"
                          placeholder="Write a short review about this particular volunteer..."></textarea>
            </div>

            <input type="hidden" id="rateVolunteerId">
            <input type="hidden" id="rateAction" value="submit_rating">
        </div>

        <div class="vc-modal-footer">
            <button class="vc-btn vc-btn-secondary"
                    onclick="closeModal('rateVolunteerModal')">
                Cancel
            </button>
            <button class="vc-btn vc-btn-primary"
                    onclick="submitRating()">
                <i class="fas fa-check"></i> Submit Rating
            </button>
        </div>
    </div>
</div>



<!-- Include Chart.js for charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="/volcon/assets/js/participation_manager.js" defer></script>

<script>
let currentEditStatus = null;

(function () {
    const banner = document.getElementById('attendanceCountdown');
    if (!banner) return;

    let remaining = parseInt(banner.dataset.seconds, 10);

    const textEl = document.getElementById('countdownText');

    function updateCountdown() {
        if (remaining <= 0) {
            banner.classList.remove('vc-banner-info', 'vc-banner-warning');
            banner.classList.add('vc-banner-danger');
            textEl.textContent = 'Attendance is now locked';
            return;
        }

        const hours = Math.floor(remaining / 3600);
        const minutes = Math.floor((remaining % 3600) / 60);

        textEl.textContent = `${hours}h ${minutes}m remaining`;

        // Change urgency dynamically
        banner.classList.remove('vc-banner-info', 'vc-banner-warning', 'vc-banner-danger');
        if (hours <= 6) {
            banner.classList.add('vc-banner-danger');
        } else if (hours <= 24) {
            banner.classList.add('vc-banner-warning');
        } else {
            banner.classList.add('vc-banner-info');
        }

        remaining -= 60;
    }

    updateCountdown();
    setInterval(updateCountdown, 60000); // update every minute
})();
</script>


<script>
let selectedRating = 0;

document.querySelectorAll('#rateStars .star').forEach(star => {
    star.addEventListener('click', () => {
        selectedRating = star.dataset.value;
        document.getElementById('rateValue').value = selectedRating;

        document.querySelectorAll('#rateStars .star').forEach(s => {
            s.classList.toggle('active', s.dataset.value <= selectedRating);
        });
    });
});

function resetStars() {
    selectedRating = 0;
    document.getElementById('rateValue').value = 0;

    document.querySelectorAll('#rateStars .star').forEach(star => {
        star.classList.remove('active');
    });
}

function setStars(rating) {
    selectedRating = rating;
    document.getElementById('rateValue').value = rating;

    document.querySelectorAll('#rateStars .star').forEach(star => {
        star.classList.toggle(
            'active',
            parseInt(star.dataset.value) <= rating
        );
    });
}

/**
  * Show modal for Rating
*/
function showRateModal(volunteerId, name) {
    document.getElementById('rateVolunteerId').value = volunteerId;
    document.getElementById('rateVolunteerName').textContent = name;
    document.getElementById('rateValue').value = 0;
    document.getElementById('rateComment').value = '';
    document.getElementById('rateAction').value = 'submit_rating';

    resetStars();
    openModal('rateVolunteerModal');
}

function showEditRateModal(volunteerId, name, rating, comment) {
    document.getElementById('rateVolunteerId').value = volunteerId;
    document.getElementById('rateVolunteerName').textContent = name;
    document.getElementById('rateValue').value = rating;
    document.getElementById('rateComment').value = comment;
    document.getElementById('rateAction').value = 'update_rating';

    setStars(rating);
    openModal('rateVolunteerModal');
}

function switchEditStatus(newStatus) {
    if (!newStatus) return;

    currentEditStatus = newStatus;
    closeAllModals();
    openEditModal(newStatus);
}

function editAttendance(
    volId,
    currentStatus,
    hours,
    reason,
    maxHours,
    name
) {
    // Store context globally
    window.editCtx = {
        volId,
        hours,
        reason,
        maxHours,
        name
    };

    currentEditStatus = currentStatus;

    // Open modal matching CURRENT status
    openEditModal(currentStatus);
}

function openEditModal(status) {
    const c = window.editCtx;

    if (status === 'attended') {
        document.getElementById('attendVolunteerId').value = c.volId;
        document.getElementById('attendVolunteerName').textContent = c.name;
        document.getElementById('attendMaxHours').value = c.maxHours;
        openModal('markAttendModal');
    }

    else if (status === 'absent') {
        document.getElementById('absentVolunteerId').value = c.volId;
        document.getElementById('absentVolunteerName').textContent = c.name;
        document.getElementById('absentReason').value = c.reason;
        openModal('markAbsentModal');
    }

    else if (status === 'incomplete') {
        document.getElementById('incompleteVolunteerId').value = c.volId;
        document.getElementById('incompleteVolunteerName').textContent = c.name;
        document.getElementById('incompleteHours').value = c.hours;
        document.getElementById('incompleteReason').value = c.reason;
        document.getElementById('incompleteMaxHours').value = c.maxHours;
        openModal('markIncompleteModal');
    }
}



function submitRating() {
    const rating = parseInt(document.getElementById('rateValue').value);

    if (rating < 1 || rating > 5) {
        alert('Please select a rating (1–5 stars).');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';

    form.innerHTML = `
        <input type="hidden" name="action" value="${document.getElementById('rateAction').value}">
        <input type="hidden" name="volunteer_id" value="${document.getElementById('rateVolunteerId').value}">
        <input type="hidden" name="rating" value="${rating}">
        <input type="hidden" name="review_text" value="${document.getElementById('rateComment').value}">
    `;

    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#rateStars .star').forEach(star => {
        star.addEventListener('click', () => {
            const value = parseInt(star.dataset.value);
            setStars(value);
        });
    });
});

</script>


<?php require_once __DIR__ . '/views/layout/footer.php'; ?>