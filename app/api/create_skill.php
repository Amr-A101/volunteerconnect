<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$user = current_user();
if (!$user || $user['role'] !== 'org') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skill_name'])) {
    $skill_name = trim($_POST['skill_name']);
    
    if (empty($skill_name) || strlen($skill_name) < 2) {
        echo json_encode(['error' => 'Skill name must be at least 2 characters']);
        exit;
    }
    
    // Check if skill already exists
    $check_stmt = $dbc->prepare("SELECT skill_id FROM skills WHERE skill_name = ?");
    $check_stmt->bind_param("s", $skill_name);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $skill = $result->fetch_assoc();
        echo json_encode(['skill_id' => $skill['skill_id'], 'skill_name' => $skill_name]);
    } else {
        // Insert new skill
        $insert_stmt = $dbc->prepare("INSERT INTO skills (skill_name) VALUES (?)");
        $insert_stmt->bind_param("s", $skill_name);
        
        if ($insert_stmt->execute()) {
            echo json_encode(['skill_id' => $insert_stmt->insert_id, 'skill_name' => $skill_name]);
        } else {
            echo json_encode(['error' => 'Failed to create skill']);
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}
?>