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

$action = $_GET['action'];
$opportunity_id = intval($_GET['id']);

switch($action) {
    case 'suspend':
        $stmt = $conn->prepare("UPDATE opportunities SET status = 'suspended' WHERE opportunity_id = ?");
        $stmt->bind_param("i", $opportunity_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Opportunity suspended successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to suspend opportunity']);
        }
        break;
        
    case 'reactivate':
        $stmt = $conn->prepare("UPDATE opportunities SET status = 'open' WHERE opportunity_id = ?");
        $stmt->bind_param("i", $opportunity_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Opportunity reactivated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reactivate opportunity']);
        }
        break;
        
    case 'delete':
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete related records first
            $conn->query("DELETE FROM opportunity_skills WHERE opportunity_id = $opportunity_id");
            $conn->query("DELETE FROM opportunity_interests WHERE opportunity_id = $opportunity_id");
            $conn->query("DELETE FROM opportunity_images WHERE opportunity_id = $opportunity_id");
            $conn->query("DELETE FROM applications WHERE opportunity_id = $opportunity_id");
            
            // Delete the opportunity
            $stmt = $conn->prepare("DELETE FROM opportunities WHERE opportunity_id = ?");
            $stmt->bind_param("i", $opportunity_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Opportunity deleted successfully']);
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to delete opportunity: ' . $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>