<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$opportunity_id = intval($_GET['id']);

// Fetch opportunity with organization details
$query = "
    SELECT 
        o.*,
        u.username as org_username,
        org.name as org_name,
        org.contact_info
    FROM opportunities o
    LEFT JOIN users u ON o.org_id = u.user_id
    LEFT JOIN organizations org ON o.org_id = org.org_id
    WHERE o.opportunity_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $opportunity_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Opportunity not found']);
    exit();
}

$opportunity = $result->fetch_assoc();

// Fetch skills for this opportunity
$skills_query = "
    SELECT s.skill_name, sc.category_name
    FROM opportunity_skills os
    JOIN skills s ON os.skill_id = s.skill_id
    LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
    WHERE os.opportunity_id = ?
";
$skills_stmt = $conn->prepare($skills_query);
$skills_stmt->bind_param("i", $opportunity_id);
$skills_stmt->execute();
$skills_result = $skills_stmt->get_result();

$skills = [];
while ($skill = $skills_result->fetch_assoc()) {
    $skills[] = $skill;
}
$opportunity['skills'] = $skills;

// Fetch interests for this opportunity
$interests_query = "
    SELECT i.interest_name, ic.category_name
    FROM opportunity_interests oi
    JOIN interests i ON oi.interest_id = i.interest_id
    LEFT JOIN interest_categories ic ON i.category_id = ic.category_id
    WHERE oi.opportunity_id = ?
";
$interests_stmt = $conn->prepare($interests_query);
$interests_stmt->bind_param("i", $opportunity_id);
$interests_stmt->execute();
$interests_result = $interests_stmt->get_result();

$interests = [];
while ($interest = $interests_result->fetch_assoc()) {
    $interests[] = $interest;
}
$opportunity['interests'] = $interests;

echo json_encode([
    'success' => true,
    'opportunity' => $opportunity
]);

$conn->close();
?>