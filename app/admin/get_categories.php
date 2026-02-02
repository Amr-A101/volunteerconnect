<?php
// get_categories.php
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

$type = $_GET['type'] ?? 'skills';

if ($type === 'skills') {
    $result = $conn->query("SELECT * FROM skill_categories ORDER BY category_name ASC");
} else {
    $result = $conn->query("SELECT * FROM interest_categories ORDER BY category_name ASC");
}

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

echo json_encode(['success' => true, 'categories' => $categories]);
$conn->close();
?>