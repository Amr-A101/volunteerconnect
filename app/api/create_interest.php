<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$user = current_user();
if (!$user || $user['role'] !== 'org') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interest_name'])) {
    $interest_name = trim($_POST['interest_name']);
    
    if (empty($interest_name) || strlen($interest_name) < 2) {
        echo json_encode(['error' => 'interest name must be at least 2 characters']);
        exit;
    }
    
    // Check if interest already exists
    $check_stmt = $dbc->prepare("SELECT interest_id FROM interests WHERE interest_name = ?");
    $check_stmt->bind_param("s", $interest_name);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $interest = $result->fetch_assoc();
        echo json_encode(['interest_id' => $interest['interest_id'], 'interest_name' => $interest_name]);
    } else {
        // Insert new interest
        $insert_stmt = $dbc->prepare("INSERT INTO interests (interest_name) VALUES (?)");
        $insert_stmt->bind_param("s", $interest_name);
        
        if ($insert_stmt->execute()) {
            echo json_encode(['interest_id' => $insert_stmt->insert_id, 'interest_name' => $interest_name]);
        } else {
            echo json_encode(['error' => 'Failed to create interest']);
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}
?>