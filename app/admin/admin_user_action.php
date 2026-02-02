<?php
session_start();
require_once __DIR__ . "/flash.php";

$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    flash('error', 'Unauthorized access');
    header("Location: login.php");
    exit();
}

// Check if it's an AJAX request
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Handle both GET and POST requests
$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'POST') ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
$type = ($method === 'POST') ? ($_POST['type'] ?? '') : ($_GET['type'] ?? '');
$id = ($method === 'POST') ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0);

$id = intval($id);

// Validate parameters
if (!in_array($type, ['vol', 'org']) || !in_array($action, ['approve', 'reject', 'suspend', 'restore', 'delete', 'edit'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    } else {
        flash('error', 'Invalid parameters');
        header("Location: dashboard_admin.php");
    }
    exit();
}

// Map actions to status values
$status_map = [
    'approve' => 'verified',
    'suspend' => 'suspended',
    'restore' => 'verified'
];

try {
    if ($action === 'delete') {
        // Start transaction
        $conn->begin_transaction();
        
        // Get username for flash message
        $userQuery = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $userQuery->bind_param("i", $id);
        $userQuery->execute();
        $userResult = $userQuery->get_result();
        $user = $userResult->fetch_assoc();
        $username = $user['username'] ?? 'User';
        
        // Delete from users table (cascade should handle related tables)
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $message = "User '$username' has been permanently deleted.";
        
    } elseif ($action === 'reject') {
        // For reject action, we delete the user
        // Get username for flash message
        $userQuery = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $userQuery->bind_param("i", $id);
        $userQuery->execute();
        $userResult = $userQuery->get_result();
        $user = $userResult->fetch_assoc();
        $username = $user['username'] ?? 'User';
        
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $message = "Registration for '$username' has been rejected.";
        
    } elseif ($action === 'edit') {
        // Handle edit action
        $name = $conn->real_escape_string($_POST['name'] ?? '');
        $email = $conn->real_escape_string($_POST['email'] ?? '');
        
        // Update users table
        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $name, $email, $id);
        $stmt->execute();
        
        // Update specific table based on type
        if ($type === 'vol') {
            $location = $conn->real_escape_string($_POST['location'] ?? '');
            $location_parts = explode(',', $location);
            $city = isset($location_parts[0]) ? trim($location_parts[0]) : '';
            $state = isset($location_parts[1]) ? trim($location_parts[1]) : '';
            
            $stmt = $conn->prepare("UPDATE volunteers SET first_name = ?, city = ?, state = ? WHERE vol_id = ?");
            $stmt->bind_param("sssi", $name, $city, $state, $id);
            $stmt->execute();
        } elseif ($type === 'org') {
            $contact_info = $conn->real_escape_string($_POST['contact_info'] ?? '');
            $stmt = $conn->prepare("UPDATE organizations SET name = ?, contact_info = ? WHERE org_id = ?");
            $stmt->bind_param("ssi", $name, $contact_info, $id);
            $stmt->execute();
        }
        
        $message = "User information updated successfully.";
        
    } else {
        // For other actions (approve, suspend, restore)
        $new_status = $status_map[$action];
        
        // Get current username for message
        $userQuery = $conn->prepare("SELECT username, status FROM users WHERE user_id = ?");
        $userQuery->bind_param("i", $id);
        $userQuery->execute();
        $userResult = $userQuery->get_result();
        $user = $userResult->fetch_assoc();
        $username = $user['username'] ?? 'User';
        $old_status = $user['status'] ?? '';
        
        // Update user status
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_status, $id);
        $stmt->execute();
        
        // Set appropriate message
        $action_display = ucfirst($action);
        $message = "User '$username' has been {$action}ed.";
        
        if ($action === 'approve' && $old_status === 'pending') {
            $message = "User '$username' has been approved and can now access the system.";
        }
    }
    
    // Success response
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'action' => $action
        ]);
    } else {
        flash('success', $message);
        header("Location: dashboard_admin.php");
    }
    
} catch (Exception $e) {
    // Error handling
    if (isset($conn) && $conn->in_transaction) {
        $conn->rollback();
    }
    
    $error_message = "Failed to process request: " . $e->getMessage();
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error_message]);
    } else {
        flash('error', $error_message);
        header("Location: dashboard_admin.php");
    }
}

$conn->close();
exit();
?>