<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'mark_read':
        markRead($user);
        break;

    case 'mark_all_read':
        markAllRead($user);
        break;

    case 'delete':
        deleteNotification($user);
        break;

    case 'fetch_latest':
        fetchLatest($user);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

function markRead($user) {
    global $dbc;

    $id = (int)($_POST['notification_id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        return;
    }

    $stmt = $dbc->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE notification_id = ?
          AND user_id = ?
          AND is_read = 0
    ");
    $stmt->bind_param("ii", $id, $user['user_id']);
    $stmt->execute();

    $success = $stmt->affected_rows > 0;
    $stmt->close();

    echo json_encode(['success' => $success]);
}


function markAllRead($user) {
    global $dbc;

    $stmt = $dbc->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
}

function deleteNotification($user) {
    global $dbc;

    $id = (int)($_POST['notification_id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false]);
        return;
    }

    $stmt = $dbc->prepare("
        UPDATE notifications
        SET is_deleted = 1
        WHERE notification_id = ?
          AND user_id = ?
          AND is_dismissible = 1
    ");
    $stmt->bind_param("ii", $id, $user['user_id']);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
}

function fetchLatest($user) {
    global $dbc;

    $stmt = $dbc->prepare("
        SELECT notification_id, title, message, type, action_url,
               is_read, is_dismissible, created_at
        FROM notifications
        WHERE user_id = ?
          AND is_deleted = 0
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'notifications' => $rows
    ]);
}


