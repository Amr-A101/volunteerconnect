<?php
// duplicate_opportunity.php

$page_title = "Duplicate Opportunity";
require_once __DIR__ . "/views/layout/header.php";

// Must be logged in as organization
$user = current_user();
if (!$user || $user['role'] !== 'org') {
    header("Location: login.php");
    exit;
}

$org_id = $user['user_id'];

// Check if opportunity ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    flash('error', 'Invalid opportunity ID.');
    header("Location: dashboard_org.php");
    exit;
}

$original_id = (int)$_GET['id'];

// Fetch the original opportunity
$stmt = $dbc->prepare("
    SELECT * FROM opportunities 
    WHERE opportunity_id = ? AND org_id = ? AND status != 'deleted'
");
$stmt->bind_param("ii", $original_id, $org_id);
$stmt->execute();
$original = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$original) {
    flash('error', 'Opportunity not found or you do not have permission to duplicate it.');
    header("Location: dashboard_org.php");
    exit;
}

// Fetch related data
$skills = [];
$interests = [];
$images = [];

$skill_stmt = $dbc->prepare("
    SELECT s.skill_id, s.skill_name 
    FROM opportunity_skills os 
    JOIN skills s ON os.skill_id = s.skill_id 
    WHERE os.opportunity_id = ?
");
$skill_stmt->bind_param("i", $original_id);
$skill_stmt->execute();
$skills = $skill_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$skill_stmt->close();

$interest_stmt = $dbc->prepare("
    SELECT i.interest_id, i.interest_name 
    FROM opportunity_interests oi 
    JOIN interests i ON oi.interest_id = i.interest_id 
    WHERE oi.opportunity_id = ?
");
$interest_stmt->bind_param("i", $original_id);
$interest_stmt->execute();
$interests = $interest_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$interest_stmt->close();

$img_stmt = $dbc->prepare("SELECT image_url FROM opportunity_images WHERE opportunity_id = ?");
$img_stmt->bind_param("i", $original_id);
$img_stmt->execute();
$images = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$img_stmt->close();

// Duplicate the opportunity
$dbc->begin_transaction();

try {
    // Insert duplicated opportunity
    $stmt = $dbc->prepare("
        INSERT INTO opportunities 
        (org_id, title, description, brief_summary, location_name, 
         city, state, country, start_date, end_date, start_time, end_time,
         application_deadline, number_of_volunteers, min_age,
         requirements, benefits, safety_notes, transportation_info,
         image_url, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
    ");
    
    $new_title = "Copy of " . $original['title'];
    $new_start_date = $original['start_date'] ? date('Y-m-d', strtotime('+1 month')) : null;
    $new_end_date = $original['end_date'] ? date('Y-m-d', strtotime('+1 month')) : null;
    
    $stmt->bind_param(
        "issssssssssssiisssss",
        $org_id,
        $new_title,
        $original['description'],
        $original['brief_summary'],
        $original['location_name'],
        $original['city'],
        $original['state'],
        $original['country'],
        $new_start_date,
        $new_end_date,
        $original['start_time'],
        $original['end_time'],
        $original['application_deadline'],
        $original['number_of_volunteers'],
        $original['min_age'],
        $original['requirements'],
        $original['benefits'],
        $original['safety_notes'],
        $original['transportation_info'],
        $original['image_url']
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to duplicate opportunity: " . $stmt->error);
    }
    
    $new_opportunity_id = $stmt->insert_id;
    $stmt->close();
    
    // Duplicate skills
    if (!empty($skills)) {
        $skill_stmt = $dbc->prepare("INSERT INTO opportunity_skills (opportunity_id, skill_id) VALUES (?, ?)");
        foreach ($skills as $skill) {
            $skill_stmt->bind_param("ii", $new_opportunity_id, $skill['skill_id']);
            $skill_stmt->execute();
        }
        $skill_stmt->close();
    }
    
    // Duplicate interests
    if (!empty($interests)) {
        $interest_stmt = $dbc->prepare("INSERT INTO opportunity_interests (opportunity_id, interest_id) VALUES (?, ?)");
        foreach ($interests as $interest) {
            $interest_stmt->bind_param("ii", $new_opportunity_id, $interest['interest_id']);
            $interest_stmt->execute();
        }
        $interest_stmt->close();
    }
    
    // Duplicate images
    if (!empty($images)) {
        $img_stmt = $dbc->prepare("INSERT INTO opportunity_images (opportunity_id, image_url) VALUES (?, ?)");
        foreach ($images as $image) {
            $img_stmt->bind_param("is", $new_opportunity_id, $image['image_url']);
            $img_stmt->execute();
        }
        $img_stmt->close();
    }
    
    $dbc->commit();
    
    flash('success', 'Opportunity duplicated successfully. You can now edit the new draft.');
    header("Location: edit_opportunity.php?id=$new_opportunity_id");
    exit;
    
} catch (Exception $e) {
    $dbc->rollback();
    flash('error', 'Error duplicating opportunity: ' . $e->getMessage());
    header("Location: dashboard_org.php");
    exit;
}
?>