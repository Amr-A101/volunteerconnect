<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/api/notify.php';

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
   VALIDATE APPLICATION ID
   =============================== */
$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($application_id <= 0) {
    flash('error', 'Invalid request.');
    header("Location: my_applications.php");
    exit;
}

/* ===============================
   FETCH APPLICATION + OPPORTUNITY
   =============================== */
$stmt = $dbc->prepare("
    SELECT 
        a.application_id,
        a.status AS app_status,
        o.opportunity_id,
        o.status AS opp_status
    FROM applications a
    JOIN opportunities o ON o.opportunity_id = a.opportunity_id
    WHERE a.application_id = ? AND a.volunteer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $application_id, $volunteer_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    flash('error', 'Application not found.');
    header("Location: my_applications.php");
    exit;
}

/* ===============================
   FETCH ORGANIZATION
=============================== */
$stmt = $dbc->prepare("
    SELECT org_id, name
    FROM organizations
    WHERE org_id = (
        SELECT org_id FROM opportunities WHERE opportunity_id = ?
    )
    LIMIT 1
");
$stmt->bind_param("i", $app['opportunity_id']);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
$stmt->close();


/* ===============================
   VALIDATION LOGIC
   =============================== */

// Opportunity state check
$blocked_opp_status = ['ongoing', 'completed', 'cancelled'];
if (in_array($app['opp_status'], $blocked_opp_status)) {
    flash('error', 'You cannot withdraw from this opportunity at this stage.');
    header("Location: my_applications.php");
    exit;
}

// Application status check
if (!in_array($app['app_status'], ['pending', 'shortlisted'])) {
    flash('error', 'This application can no longer be withdrawn.');
    header("Location: my_applications.php");
    exit;
}

/* ===============================
   WITHDRAW APPLICATION
   =============================== */
$stmt = $dbc->prepare("
    UPDATE applications
    SET status = 'withdrawn', response_at = NOW()
    WHERE application_id = ? AND volunteer_id = ?
");
$stmt->bind_param("ii", $application_id, $volunteer_id);

if (!$stmt->execute()) {
    flash('error', 'Failed to withdraw application.');
    header("Location: my_applications.php");
    exit;
}

$stmt->close();

createNotification([
    'user_id' => $org['org_id'],
    'role_target' => 'organization',
    'title' => 'Application Withdrawn',
    'message' => $user['first_name'].' '.$user['last_name'].' has withdrawn their application.',
    'type' => 'info',
    'action_url' => "/volcon/app/applicants_manager.php?id={$app['opportunity_id']}",
    'context_type' => 'application',
    'context_id' => $application_id,
    'created_by' => 'system'
]);

createNotification([
    'user_id' => $volunteer_id,
    'role_target' => 'volunteer',
    'title' => 'Application Withdrawn',
    'message' => 'You have successfully withdrawn your application.',
    'type' => 'warning',
    'action_url' => "/volcon/app/my_applications.php",
    'context_type' => 'application',
    'context_id' => $application_id,
    'created_by' => 'system'
]);


/* ===============================
   SUCCESS
   =============================== */
flash('success', 'Your application has been withdrawn.');
header("Location: my_applications.php");
exit;
