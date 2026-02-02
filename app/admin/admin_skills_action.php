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
        $skill_name = trim($_POST['skill_name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : NULL;
        
        if (empty($skill_name)) {
            flash('error', 'Skill name is required');
            header("Location: dashboard_admin.php#skills");
            exit();
        }
        
        // Check if skill already exists (case-insensitive)
        $check_stmt = $conn->prepare("SELECT skill_id, skill_name FROM skills WHERE LOWER(skill_name) = LOWER(?)");
        $check_stmt->bind_param("s", $skill_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $existing_skill = $check_result->fetch_assoc();
            flash('error', "Skill '" . htmlspecialchars($existing_skill['skill_name']) . "' already exists!");
            header("Location: dashboard_admin.php#skills");
            exit();
        }
        
        // If category_id is empty string, set to NULL
        if ($category_id === '') {
            $category_id = NULL;
        }
        
        $stmt = $conn->prepare("INSERT INTO skills (skill_name, category_id) VALUES (?, ?)");
        $stmt->bind_param("si", $skill_name, $category_id);
        
        if ($stmt->execute()) {
            flash('success', 'Skill added successfully');
        } else {
            // Check for duplicate key error
            if ($conn->errno == 1062) { // MySQL duplicate entry error code
                flash('error', "Skill '" . htmlspecialchars($skill_name) . "' already exists!");
            } else {
                flash('error', 'Failed to add skill: ' . $conn->error);
            }
        }
        
        header("Location: dashboard_admin.php#skills");
        exit();
        
    case 'edit':
        // Handle AJAX edit request
        if (!isset($_POST['skill_id']) || !isset($_POST['skill_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }
        
        $skill_id = (int)$_POST['skill_id'];
        $skill_name = trim($_POST['skill_name']);
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : NULL;
        
        header('Content-Type: application/json');
        
        if (empty($skill_name)) {
            echo json_encode(['success' => false, 'message' => 'Skill name is required']);
            exit();
        }
        
        // Check if skill already exists (excluding current one, case-insensitive)
        $check_stmt = $conn->prepare("SELECT skill_id FROM skills WHERE LOWER(skill_name) = LOWER(?) AND skill_id != ?");
        $check_stmt->bind_param("si", $skill_name, $skill_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Skill name already exists']);
            exit();
        }
        
        // If category_id is empty string, set to NULL
        if ($category_id === '') {
            $category_id = NULL;
        }
        
        $stmt = $conn->prepare("UPDATE skills SET skill_name = ?, category_id = ? WHERE skill_id = ?");
        $stmt->bind_param("sii", $skill_name, $category_id, $skill_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'Skill updated successfully',
                'skill_id' => $skill_id
            ]);
        } else {
            // Check for duplicate key error
            if ($conn->errno == 1062) {
                echo json_encode(['success' => false, 'message' => 'Skill name already exists']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update skill: ' . $conn->error]);
            }
        }
        exit();
        
    case 'delete':
        // Handle AJAX delete request
        if (!isset($_GET['id'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing skill ID']);
            exit();
        }
        
        $skill_id = (int)$_GET['id'];
        
        header('Content-Type: application/json');
        
        // Check if skill is being used by volunteers
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM volunteer_skills WHERE skill_id = ?");
        $check_stmt->bind_param("i", $skill_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_assoc();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete: Skill is in use by volunteers']);
            exit();
        }
        
        // Check if skill is being used by opportunities
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM opportunity_skills WHERE skill_id = ?");
        $check_stmt->bind_param("i", $skill_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $row = $check_result->fetch_assoc();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete: Skill is in use by opportunities']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM skills WHERE skill_id = ?");
        $stmt->bind_param("i", $skill_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Skill deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete skill: ' . $conn->error]);
        }
        exit();
        
    default:
        flash('error', 'Invalid action');
        header("Location: dashboard_admin.php");
        exit();
}

$conn->close();
?>