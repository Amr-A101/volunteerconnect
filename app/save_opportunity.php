<?php

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/flash.php';

/* ===============================
   AUTH CHECK
   =============================== */
$user = current_user();

if (!$user || $user['role'] !== 'vol') {
    flash('error', 'You must be logged in as a volunteer to save opportunities.');
    header("Location: login.php");
    exit;
}

$volunteer_id = (int)$user['user_id'];

/* ===============================
   VALIDATE INPUT
   =============================== */
$opportunity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_url = $_SERVER['HTTP_REFERER'] ?? 'browse_opportunities.php';

if ($opportunity_id <= 0) {
    flash('error', 'Invalid opportunity.');
    header("Location: $return_url");
    exit;
}

/* ===============================
   CHECK OPPORTUNITY EXISTS
   =============================== */
$stmt = $dbc->prepare("
    SELECT status
    FROM opportunities
    WHERE opportunity_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$opp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opp || $opp['status'] === 'deleted') {
    flash('error', 'This opportunity is no longer available.');
    header("Location: $return_url");
    exit;
}

/* ===============================
   CHECK IF ALREADY SAVED
   =============================== */
$stmt = $dbc->prepare("
    SELECT save_id
    FROM saved_opportunities
    WHERE volunteer_id = ? AND opportunity_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $volunteer_id, $opportunity_id);
$stmt->execute();
$saved = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ===============================
   TOGGLE SAVE
   =============================== */
if ($saved) {
    // UNSAVE
    $stmt = $dbc->prepare("
        DELETE FROM saved_opportunities
        WHERE volunteer_id = ? AND opportunity_id = ?
    ");
    $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
    $stmt->execute();
    $stmt->close();

    flash('success', 'Opportunity removed from saved list.');
} else {
    // SAVE
    $stmt = $dbc->prepare("
        INSERT INTO saved_opportunities (volunteer_id, opportunity_id)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $volunteer_id, $opportunity_id);
    $stmt->execute();
    $stmt->close();

    flash('success', 'Opportunity saved successfully.');
}

/* ===============================
   REDIRECT BACK
   =============================== */
header("Location: $return_url");
exit;
