<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/api/notify.php';


function redirect_back($fallback = 'opportunities.php') {
    $url = $_SERVER['HTTP_REFERER'] ?? $fallback;
    header("Location: $url");
    exit;
}


/* ===============================
   AUTH
   =============================== */
$user = current_user();
if (!$user || $user['role'] !== 'vol') {
    header("Location: login.php");
    exit;
}

$volunteer_id = (int)$user['user_id'];

/* ===============================
   VALIDATE OPPORTUNITY ID
   =============================== */
$opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($opportunity_id <= 0) {
    flash('error', 'Invalid opportunity.');
    redirect_back();
}


/* ===============================
   FETCH OPPORTUNITY + STATS
=============================== */
$stmt = $dbc->prepare("
    SELECT 
        o.opportunity_id,
        o.status,
        o.min_age,
        o.application_deadline,
        o.start_date,
        o.number_of_volunteers,
        (
            SELECT COUNT(*) 
            FROM applications 
            WHERE opportunity_id = o.opportunity_id 
              AND status = 'accepted'
        ) AS accepted_count
    FROM opportunities o
    WHERE o.opportunity_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$opp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opp) {
    flash('error', 'Opportunity not found.');
    redirect_back();
}

$stmt = $dbc->prepare("
    SELECT org_id, name
    FROM organizations
    WHERE org_id = (
        SELECT org_id FROM opportunities WHERE opportunity_id = ?
    )
    LIMIT 1
");
$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$org) {
    flash('error', 'Organization not found.');
    redirect_back();
}


/* ===============================
   OPPORTUNITY VALIDATION
   =============================== */
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

$blocked_statuses = ['draft','closed','ongoing','completed','cancelled','deleted','suspended'];

if (in_array($opp['status'], $blocked_statuses)) {
    flash('error', 'This opportunity is not accepting applications.');
    redirect_back();
}


if (!empty($opp['application_deadline']) && $opp['application_deadline'] < $now) {
    flash('error', 'Application deadline has passed.');
    redirect_back();
}

if (!empty($opp['start_date']) && $opp['start_date'] < $today) {
    flash('error', 'This opportunity has already started.');
    redirect_back();
}

if (!empty($opp['number_of_volunteers']) && $opp['accepted_count'] >= $opp['number_of_volunteers']
) {
    flash('error', 'All volunteer slots have been filled.');
    redirect_back();
}


/* ===============================
   CHECK PARTICIPATION
=============================== */
$stmt = $dbc->prepare("
    SELECT participation_id
    FROM participation
    WHERE opportunity_id = ? AND volunteer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $opportunity_id, $volunteer_id);
$stmt->execute();
$participation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($participation) {
    flash('error', 'You have already participated in this opportunity.');
    redirect_back();
}


/* ===============================
   FETCH VOLUNTEER PROFILE
   =============================== */
$stmt = $dbc->prepare("
    SELECT birthdate
    FROM volunteers
    WHERE vol_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $volunteer_id);
$stmt->execute();
$vol = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vol) {
    flash('error', 'Volunteer profile not found.');
    header("Location: profile_vol.php");
    exit;
}

/* ===============================
   AGE CHECK
   =============================== */
if (!empty($opp['min_age'])) {
    $age = (int)date_diff(date_create($vol['birthdate']), date_create('today'))->y;
    if ($age < $opp['min_age']) {
        flash('error', 'You do not meet the minimum age requirement.');
        redirect_back();
    }
}

/* ===============================
   CHECK EXISTING APPLICATION
   =============================== */
$stmt = $dbc->prepare("
    SELECT application_id, status, applied_at, response_at
    FROM applications
    WHERE opportunity_id = ? AND volunteer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $opportunity_id, $volunteer_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();


/* ===============================
   APPLY / REAPPLY LOGIC
   =============================== */

$dbc->begin_transaction();

try {
    if (!$app) {
        // FIRST-TIME APPLY
        $stmt = $dbc->prepare("
            INSERT INTO applications (volunteer_id, opportunity_id, status)
            VALUES (?, ?, 'pending')
        ");
        $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
        $stmt->execute();
        $stmt->close();

    } else {
        $current_status = $app['status'];

        // Active applications
        if (in_array($current_status, ['pending','shortlisted'])) {
            throw new Exception('You already have an active application.');
        }

        // Accepted is final
        if ($current_status === 'accepted') {
            throw new Exception('Your application has already been accepted.');
        }

        $now = time();

        /* ===============================
        WITHDRAWN COOLDOWN
        =============================== */
        if ($current_status === 'withdrawn') {

            if (empty($app['response_at'])) {
                throw new Exception('Invalid application state. Please contact support at support@volunteerconnect.org.');
            }

            $cooldown_hours = 0.5; //6
            $last = strtotime($app['response_at']);

            $elapsed = $now - $last;

            // if ($elapsed < ($cooldown_hours * 3600)) {
            //     $remaining = ceil(
            //         (($cooldown_hours * 3600) - $elapsed) / 3600
            //     );

            //     throw new Exception(
            //         "Please wait {$remaining} more hour(s) before reapplying."
            //     );
            // }

            if ($elapsed < ($cooldown_hours * 60)) {
                $remaining = ceil(
                    (($cooldown_hours * 60) - $elapsed) / 60
                );

                throw new Exception(
                    "Please wait {$remaining} more minute(s) before reapplying."
                );
            }
        }

        // Rejected - ONLY allow if opportunity is open again
        if ($current_status === 'rejected') {

            if ($opp['status'] !== 'open') {
                throw new Exception(
                    'This opportunity is not accepting reapplications.'
                );
            }

            if (empty($app['response_at'])) {
                // fallback for legacy / system-rejected applications
                $last = strtotime($app['applied_at']);
            } else {
                $last = strtotime($app['response_at']);
            }

            $cooldown_hours = 0.5; //12
            $last = strtotime($app['response_at']);
            $elapsed = time() - $last;

            // if ($elapsed < ($cooldown_hours * 3600)) {
            //     $remaining = ceil(
            //         (($cooldown_hours * 3600) - $elapsed) / 3600
            //     );

            //     throw new Exception(
            //         "Please wait {$remaining} more hour(s) before reapplying after rejection."
            //     );
            // }

            if ($elapsed < ($cooldown_hours * 60)) {
                $remaining = ceil(
                    (($cooldown_hours * 60) - $elapsed) / 60
                );

                throw new Exception(
                    "Please wait {$remaining} more minute(s) before reapplying after rejection."
                );
            }
        }

        // Final reapply
        if (in_array($current_status, ['withdrawn','rejected'])) {
            $stmt = $dbc->prepare("
                UPDATE applications
                SET status = 'pending',
                    applied_at = NOW()
                WHERE application_id = ?
            ");
            $stmt->bind_param("i", $app['application_id']);
            $stmt->execute();
            $stmt->close();
        }
    }


    $dbc->commit();

    /* =========================================
        NOTIFY ORG & VOLUNTEER (SYSTEM MESSAGE)
    ========================================= */

    $is_reapply = (bool)$app;
    $title_org = $is_reapply ? 'Reapplication Received' : 'New Application Received';
    $title_vol = $is_reapply ? 'Reapplication Submitted' : 'Application Submitted';


    createNotification([
        'user_id' => $org['org_id'],
        'role_target' => 'org',
        'title' => $title_org,
        'message' => $user['first_name'] . ' ' . $user['last_name']
            . ' has applied to your opportunity.',
        'type' => 'info',
        'action_url' => "/volcon/app/applicants_manager.php?id={$opportunity_id}",
        'context_type' => 'opportunity',
        'context_id' => $opportunity_id,
        'is_dismissible' => 1,
        'created_by' => 'system',
        'created_by_id' => null
    ]);

    createNotification([
        'user_id' => $volunteer_id,
        'role_target' => 'vol',
        'title' => $title_vol,
        'message' => 'Your application has been sent successfully.',
        'type' => 'success',
        'action_url' => "/volcon/app/my_applications.php",
        'context_type' => 'application',
        'context_id' => $opportunity_id,
        'is_dismissible' => 1,
        'created_by' => 'system',
        'created_by_id' => null
    ]);


    flash('success', 'Your application has been submitted. You’ll be notified of updates.');
    header("Location: my_applications.php");
    exit;
    
} catch (Exception $e) {
    $dbc->rollback();
    flash('error', $e->getMessage());
    redirect_back();
}

?>