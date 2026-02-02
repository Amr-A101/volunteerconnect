<?php
session_start();

require_once __DIR__ . "/flash.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    flash('error', 'Unauthorized access');
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "volcon");
if ($conn->connect_error) {
    flash('error', 'Database connection failed');
    header("Location: dashboard_admin.php");
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch($action) {
    case 'add':
        // Handle form submission (non-AJAX)
        $interest_name = trim($_POST['interest_name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : NULL;
        
        if (empty($interest_name)) {
            flash('error', 'Interest name is required');
            header("Location: dashboard_admin.php#interests");
            exit();
        }
        
        // Check if interest already exists (case-insensitive)
        $check_stmt = $conn->prepare("SELECT interest_id, interest_name FROM interests WHERE LOWER(interest_name) = LOWER(?)");
        $check_stmt->bind_param("s", $interest_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $existing_interest = $check_result->fetch_assoc();
            flash('error', "Interest '" . htmlspecialchars($existing_interest['interest_name']) . "' already exists!");
            header("Location: dashboard_admin.php#interests");
            exit();
        }
        
        // If category_id is empty string, set to NULL
        if ($category_id === '') {
            $category_id = NULL;
        }
        
        $stmt = $conn->prepare("INSERT INTO interests (interest_name, category_id) VALUES (?, ?)");
        $stmt->bind_param("si", $interest_name, $category_id);
        
        if ($stmt->execute()) {
            flash('success', 'Interest added successfully');
        } else {
            // Check for duplicate key error
            if ($conn->errno == 1062) { // MySQL duplicate entry error code
                flash('error', "Interest '" . htmlspecialchars($interest_name) . "' already exists!");
            } else {
                flash('error', 'Failed to add interest: ' . $conn->error);
            }
        }
        
        header("Location: dashboard_admin.php#interests");
        exit();
        
    case 'edit':
        // Handle AJAX edit request
        if (!isset($_POST['interest_id']) || !isset($_POST['interest_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }
        
        $interest_id = (int)$_POST['interest_id'];
        $interest_name = trim($_POST['interest_name']);
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : NULL;
        
        header('Content-Type: application/json');
        
        if (empty($interest_name)) {
            echo json_encode(['success' => false, 'message' => 'Interest name is required']);
            exit();
        }
        
        // Check if interest already exists (excluding current one, case-insensitive)
        $check_stmt = $conn->prepare("SELECT interest_id FROM interests WHERE LOWER(interest_name) = LOWER(?) AND interest_id != ?");
        $check_stmt->bind_param("si", $interest_name, $interest_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Interest name already exists']);
            exit();
        }
        
        // If category_id is empty string, set to NULL
        if ($category_id === '') {
            $category_id = NULL;
        }
        
        $stmt = $conn->prepare("UPDATE interests SET interest_name = ?, category_id = ? WHERE interest_id = ?");
        $stmt->bind_param("sii", $interest_name, $category_id, $interest_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'Interest updated successfully',
                'interest_id' => $interest_id
            ]);
        } else {
            // Check for duplicate key error
            if ($conn->errno == 1062) {
                echo json_encode(['success' => false, 'message' => 'Interest name already exists']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update interest: ' . $conn->error]);
            }
        }
        exit();
        
    case 'delete':
        // Handle AJAX delete request
        if (!isset($_GET['id'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing interest ID']);
            exit();
        }
        
        $interest_id = (int)$_GET['id'];
        
        header('Content-Type: application/json');
        
        // Check if interest is being used by volunteers
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM volunteer_interests WHERE interest_id = ?");
        $check_stmt->bind_param("i", $interest_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_assoc();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete: Interest is in use by volunteers']);
            exit();
        }
        
        // Check if interest is being used by opportunities
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM opportunity_interests WHERE interest_id = ?");
        $check_stmt->bind_param("i", $interest_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_assoc();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete: Interest is in use by opportunities']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM interests WHERE interest_id = ?");
        $stmt->bind_param("i", $interest_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Interest deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete interest: ' . $conn->error]);
        }
        exit();
        
    default:
        flash('error', 'Invalid action');
        header("Location: dashboard_admin.php");
        exit();
}

$conn->close();
?>