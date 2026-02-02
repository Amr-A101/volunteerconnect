<?php


require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../api/notify.php';

require_role('admin');

$user = current_user();

$title = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');
$type = $_POST['type'] ?? 'system';
$role_target = $_POST['role_target'] ?? 'all';
$action_url = $_POST['action_url'] ?: null;

if ($title === '' || $message === '') {
    header("Location: ../announcement.php?error=invalid");
    exit;
}

broadcastAnnouncement([
    'title' => $title,
    'message' => $message,
    'type' => $type,
    'role_target' => $role_target,
    'action_url' => $action_url,
    'created_by_id' => $user['user_id']
]);

header("Location: ../announcement.php?success=1");
exit;
