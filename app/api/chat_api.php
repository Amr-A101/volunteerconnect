<?php
/**
 * Chat API - Backend endpoints for chat functionality
 * Handles: fetch conversations, fetch messages, send messages, etc.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

$loggedUser = current_user();
if (!$loggedUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_user_id = (int)$loggedUser['user_id'];
$role = $_SESSION['role'] ?? 'guest';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_conversations':
        getConversations($dbc, $current_user_id, $role);
        break;
    
    case 'get_messages':
        getMessages($dbc, $current_user_id);
        break;
    
    case 'send_message':
        sendMessage($dbc, $current_user_id, $role);
        break;
    
    case 'mark_read':
        markAsRead($dbc, $current_user_id);
        break;
    
    case 'delete_message':
        deleteMessage($dbc, $current_user_id);
        break;
    
    case 'flag_message':
        flagMessage($dbc, $current_user_id);
        break;
    
    case 'archive_conversation':
        archiveConversation($dbc, $current_user_id);
        break;
    
    case 'start_conversation':
        startConversation($dbc, $current_user_id, $role);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

/**
 * Get all conversations for current user
 */
function getConversations($dbc, $user_id, $role) {
    $stmt = $dbc->prepare("
        SELECT 
            cc.conversation_id,
            cc.type,
            cc.opportunity_id,
            cc.is_archived,
            cc.created_at,
            
            -- Get the other participant's info (for direct chats)
            CASE 
                WHEN cc.type = 'direct' THEN
                    (SELECT cp2.user_id FROM chat_participants cp2 
                     WHERE cp2.conversation_id = cc.conversation_id 
                     AND cp2.user_id != ? LIMIT 1)
                ELSE NULL
            END as other_user_id,
            
            -- Last message info
            lm.message_text as last_message,
            lm.message_type as last_message_type,
            lm.is_deleted_for_everyone,
            lm.created_at as last_message_time,
            lm.sender_id as last_sender_id,
            
            -- Unread count
            (SELECT COUNT(*) 
             FROM chat_messages cm2
             WHERE cm2.conversation_id = cc.conversation_id
             AND cm2.sender_id != ?
             AND cm2.created_at > COALESCE(cp.last_read_at, '1970-01-01')
             AND cm2.is_deleted_for_everyone = 0
             AND NOT EXISTS (
                SELECT 1 FROM chat_message_visibility cmv 
                WHERE cmv.message_id = cm2.message_id 
                AND cmv.user_id = ? 
                AND cmv.is_hidden = 1
             )
            ) as unread_count,
            
            -- Opportunity info if exists
            o.title as opportunity_title
            
        FROM chat_conversations cc
        JOIN chat_participants cp ON cp.conversation_id = cc.conversation_id
        LEFT JOIN chat_messages lm ON lm.message_id = cc.last_message_id
        LEFT JOIN opportunities o ON o.opportunity_id = cc.opportunity_id
        WHERE cp.user_id = ?
        AND cc.is_archived = 0
        ORDER BY lm.created_at DESC, cc.created_at DESC
    ");
    
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversations = [];
    
    while ($row = $result->fetch_assoc()) {
        // Get other user's details
        $other_name = 'Unknown';
        $other_avatar = '/volcon/assets/uploads/default-avatar.png';
        
        if ($row['type'] === 'direct' && $row['other_user_id']) {
            $other_user = getUserDetails($dbc, $row['other_user_id']);
            $other_name = $other_user['name'];
            $other_avatar = $other_user['avatar'];
        } elseif ($row['opportunity_id']) {
            $other_name = $row['opportunity_title'] ?? 'Group Chat';
        }
        
        // Format last message
        $last_message = '';
        if ($row['is_deleted_for_everyone']) {
            $last_message = 'Message deleted';
        } elseif ($row['last_message_type'] === 'system') {
            $last_message = $row['last_message'];
        } elseif ($row['last_message']) {
            // Check if it has attachment
            $has_attachment = hasAttachment($dbc, $row['last_message_id'] ?? 0);
            $last_message = $has_attachment ? 'Sent an attachment' : $row['last_message'];
        } else {
            $last_message = 'No messages yet';
        }
        
        // Format time
        $last_time = formatMessageTime($row['last_message_time']);

        // After getting $other_user details, determine role
        $other_user_role = null;
        if ($row['type'] === 'direct' && $row['other_user_id']) {
            // Check if other user is org or vol
            $check_org = $dbc->prepare("SELECT 1 FROM organizations WHERE org_id = ?");
            $check_org->bind_param("i", $row['other_user_id']);
            $check_org->execute();
            $is_org = $check_org->get_result()->fetch_assoc();
            $check_org->close();
            
            $other_user_role = $is_org ? 'org' : 'vol';
        }

        
        $conversations[] = [
            'conversation_id' => (int)$row['conversation_id'],
            'type' => $row['type'],
            'name' => $other_name,
            'avatar' => $other_avatar,
            'last_message' => substr($last_message, 0, 50),
            'last_time' => $last_time,
            'unread' => (int)$row['unread_count'],
            'opportunity_id' => $row['opportunity_id'],
            'is_deleted' => (bool)$row['is_deleted_for_everyone'],
            'has_attachment' => $has_attachment ?? false,
            'other_user_id' => $row['other_user_id'] ? (int)$row['other_user_id'] : null,
            'other_user_role' => $other_user_role
        ];
    }
    
    $stmt->close();
    echo json_encode(['success' => true, 'conversations' => $conversations]);
}

/**
 * Get messages for a conversation
 */
function getMessages($dbc, $user_id) {
    $conversation_id = (int)($_GET['conversation_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    
    if (!$conversation_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Conversation ID required']);
        return;
    }
    
    // Verify user is participant
    $stmt = $dbc->prepare("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        http_response_code(403);
        echo json_encode(['error' => 'Not a participant']);
        return;
    }
    $stmt->close();
    
    // Get messages
    $stmt = $dbc->prepare("
        SELECT 
            cm.message_id,
            cm.sender_id,
            cm.message_text,
            cm.message_type,
            cm.is_deleted_for_everyone,
            cm.is_flagged,
            cm.created_at,
            
            -- Check if hidden for this user
            COALESCE(cmv.is_hidden, 0) as is_hidden,
            
            -- Check if this user has flagged this message
            (SELECT COUNT(*) FROM chat_flags cf WHERE cf.message_id = cm.message_id AND cf.user_id = ?) as is_flagged_by_me
            
        FROM chat_messages cm
        LEFT JOIN chat_message_visibility cmv ON cmv.message_id = cm.message_id AND cmv.user_id = ?
        WHERE cm.conversation_id = ?
        AND (cm.is_deleted_for_everyone = 0 OR cm.sender_id = ?)
        ORDER BY cm.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->bind_param("iiiiii", $user_id, $user_id, $conversation_id, $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    
    while ($row = $result->fetch_assoc()) {
        if ($row['is_hidden']) continue;
        
        $sender = getUserDetails($dbc, $row['sender_id']);
        
        // Get attachments
        $attachments = getMessageAttachments($dbc, $row['message_id']);
        
        $messages[] = [
            'message_id' => (int)$row['message_id'],
            'sender_id' => (int)$row['sender_id'],
            'sender_name' => $sender['name'],
            'sender_avatar' => $sender['avatar'],
            'message_text' => $row['is_deleted_for_everyone'] ? null : $row['message_text'],
            'message_type' => $row['message_type'],
            'is_deleted' => (bool)$row['is_deleted_for_everyone'],
            'is_flagged' => (bool)$row['is_flagged'],
            'is_flagged_by_me' => (bool)$row['is_flagged_by_me'],
            'created_at' => $row['created_at'],
            'time_formatted' => date('H:i', strtotime($row['created_at'])),
            'is_own' => (int)$row['sender_id'] === $user_id,
            'attachments' => $attachments
        ];
    }
    
    $stmt->close();
    
    // Reverse to show oldest first
    $messages = array_reverse($messages);
    
    echo json_encode(['success' => true, 'messages' => $messages]);
}

/**
 * Send a new message
 */
function sendMessage($dbc, $user_id, $role) {
    $conversation_id = (int)($_POST['conversation_id'] ?? 0);
    $message_text = trim($_POST['message_text'] ?? '');
    $message_type = $_POST['message_type'] ?? 'text';
    
    if (!$conversation_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Conversation ID required']);
        return;
    }
    
    if (empty($message_text) && empty($_FILES['attachment'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Message text or attachment required']);
        return;
    }
    
    // Verify user is participant
    $stmt = $dbc->prepare("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        http_response_code(403);
        echo json_encode(['error' => 'Not a participant']);
        return;
    }
    $stmt->close();
    
    // Insert message
    $stmt = $dbc->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, message_text, message_type)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("iiss", $conversation_id, $user_id, $message_text, $message_type);
    
    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message']);
        return;
    }
    
    $message_id = $stmt->insert_id;
    $stmt->close();
    
    // Update last_message_id in conversation
    $stmt = $dbc->prepare("UPDATE chat_conversations SET last_message_id = ? WHERE conversation_id = ?");
    $stmt->bind_param("ii", $message_id, $conversation_id);
    $stmt->execute();
    $stmt->close();
    
    // Handle file upload
    $attachment = null;
    if (!empty($_FILES['attachment']['name'])) {
        $attachment = handleFileUpload($_FILES['attachment'], $message_id, $dbc);
    }
    
    echo json_encode([
        'success' => true, 
        'message_id' => $message_id,
        'attachment' => $attachment
    ]);
}

/**
 * Mark conversation as read
 */
function markAsRead($dbc, $user_id) {
    $conversation_id = (int)($_POST['conversation_id'] ?? 0);
    
    if (!$conversation_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Conversation ID required']);
        return;
    }
    
    $stmt = $dbc->prepare("
        UPDATE chat_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}

/**
 * Delete message (for self or everyone)
 */
function deleteMessage($dbc, $user_id) {
    $message_id = (int)($_POST['message_id'] ?? 0);
    $delete_for_everyone = (int)($_POST['delete_for_everyone'] ?? 0);
    
    if (!$message_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Message ID required']);
        return;
    }
    
    // Get message
    $stmt = $dbc->prepare("SELECT sender_id, conversation_id FROM chat_messages WHERE message_id = ?");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $msg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$msg) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        return;
    }
    
    if ($delete_for_everyone) {
        // Only sender can delete for everyone
        if ($msg['sender_id'] !== $user_id) {
            http_response_code(403);
            echo json_encode(['error' => 'Can only delete your own messages']);
            return;
        }
        
        $stmt = $dbc->prepare("
            UPDATE chat_messages 
            SET is_deleted_for_everyone = 1, deleted_at = NOW(), message_text = NULL 
            WHERE message_id = ?
        ");
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Hide for this user only
        $stmt = $dbc->prepare("
            INSERT INTO chat_message_visibility (message_id, user_id, is_hidden) 
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE is_hidden = 1
        ");
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => true]);
}

/**
 * Flag message as inappropriate
 */
function flagMessage($dbc, $user_id) {
    $message_id = (int)($_POST['message_id'] ?? 0);
    $category = $_POST['category'] ?? 'other';
    
    if (!$message_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Message ID required']);
        return;
    }
    
    $allowed_categories = ['spam', 'harassment', 'inappropriate', 'other'];
    if (!in_array($category, $allowed_categories)) {
        $category = 'other';
    }
    
    // Check if already flagged by this user
    $stmt = $dbc->prepare("SELECT 1 FROM chat_flags WHERE message_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $message_id, $user_id);
    $stmt->execute();
    $already_flagged = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($already_flagged) {
        echo json_encode(['success' => false, 'error' => 'You have already reported this message']);
        return;
    }
    
    // Insert flag record
    $stmt = $dbc->prepare("
        INSERT INTO chat_flags (message_id, user_id, category, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $message_id, $user_id, $category);
    $stmt->execute();
    $stmt->close();
    
    // Update message as flagged
    $stmt = $dbc->prepare("
        UPDATE chat_messages 
        SET is_flagged = 1, abuse_category = ? 
        WHERE message_id = ?
    ");
    $stmt->bind_param("si", $category, $message_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}

/**
 * Archive conversation
 */
function archiveConversation($dbc, $user_id) {
    $conversation_id = (int)($_POST['conversation_id'] ?? 0);
    
    if (!$conversation_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Conversation ID required']);
        return;
    }
    
    // Verify participant
    $stmt = $dbc->prepare("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        http_response_code(403);
        echo json_encode(['error' => 'Not a participant']);
        return;
    }
    $stmt->close();
    
    $stmt = $dbc->prepare("UPDATE chat_conversations SET is_archived = 1 WHERE conversation_id = ?");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}

/**
 * Start a new conversation
 */
function startConversation($dbc, $user_id, $role) {
    $other_user_id = (int)($_POST['other_user_id'] ?? 0);
    $opportunity_id = (int)($_POST['opportunity_id'] ?? 0);
    
    if (!$other_user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Other user ID required']);
        return;
    }
    
    // Check if conversation already exists
    $stmt = $dbc->prepare("
        SELECT cc.conversation_id 
        FROM chat_conversations cc
        JOIN chat_participants cp1 ON cp1.conversation_id = cc.conversation_id
        JOIN chat_participants cp2 ON cp2.conversation_id = cc.conversation_id
        WHERE cp1.user_id = ? AND cp2.user_id = ? AND cc.type = 'direct'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $user_id, $other_user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        echo json_encode(['success' => true, 'conversation_id' => $existing['conversation_id'], 'existing' => true]);
        return;
    }
    
    // Create new conversation
    $stmt = $dbc->prepare("INSERT INTO chat_conversations (type, opportunity_id) VALUES ('direct', ?)");
    $opp_id_null = $opportunity_id ?: null;
    $stmt->bind_param("i", $opp_id_null);
    $stmt->execute();
    $conversation_id = $stmt->insert_id;
    $stmt->close();
    
    // Add participants
    $stmt = $dbc->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
    $stmt->bind_param("iiii", $conversation_id, $user_id, $conversation_id, $other_user_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true, 'conversation_id' => $conversation_id, 'existing' => false]);
}

/**
 * Helper: Get user details
 */
function getUserDetails($dbc, $user_id) {
    static $cache = [];
    
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    
    // Check in volunteers
    $stmt = $dbc->prepare("SELECT first_name, last_name, profile_picture FROM volunteers WHERE vol_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $cache[$user_id] = [
            'name' => $result['first_name'] . ' ' . $result['last_name'],
            'avatar' => $result['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png'
        ];
        return $cache[$user_id];
    }
    
    // Check in organizations
    $stmt = $dbc->prepare("SELECT name, profile_picture FROM organizations WHERE org_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $cache[$user_id] = [
            'name' => $result['name'],
            'avatar' => $result['profile_picture'] ?: '/volcon/assets/uploads/default-avatar.png'
        ];
        return $cache[$user_id];
    }
    
    $cache[$user_id] = [
        'name' => 'Unknown User',
        'avatar' => '/volcon/assets/uploads/default-avatar.png'
    ];
    return $cache[$user_id];
}

/**
 * Helper: Check if message has attachment
 */
function hasAttachment($dbc, $message_id) {
    $stmt = $dbc->prepare("SELECT 1 FROM chat_attachments WHERE message_id = ? LIMIT 1");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $has = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $has;
}

/**
 * Helper: Get message attachments
 */
function getMessageAttachments($dbc, $message_id) {
    $stmt = $dbc->prepare("SELECT attachment_id, file_path, file_type, file_size FROM chat_attachments WHERE message_id = ?");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attachments = [];
    
    while ($row = $result->fetch_assoc()) {
        $attachments[] = [
            'id' => (int)$row['attachment_id'],
            'path' => $row['file_path'],
            'type' => $row['file_type'] ?: 'application/octet-stream',
            'size' => formatFileSize($row['file_size'] ?: 0)
        ];
    }
    
    $stmt->close();
    return $attachments;
}

/**
 * Helper: Handle file upload
 */
function handleFileUpload($file, $message_id, $dbc) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    if (!in_array($file['type'], $allowed_types)) {
        return null;
    }
    
    if ($file['size'] > $max_size) {
        return null;
    }
    
    // Use absolute path for directory
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/volcon/assets/uploads/chat/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('chat_') . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Store relative path in database
        $db_path = '/volcon/assets/uploads/chat/' . $filename;
        
        $stmt = $dbc->prepare("INSERT INTO chat_attachments (message_id, file_path, file_type, file_size) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $message_id, $db_path, $file['type'], $file['size']);
        $stmt->execute();
        $attachment_id = $stmt->insert_id;
        $stmt->close();
        
        return [
            'id' => $attachment_id,
            'path' => $db_path,
            'type' => $file['type'],
            'size' => formatFileSize($file['size'])
        ];
    }
    
    return null;
}

/**
 * Helper: Format message time
 */
function formatMessageTime($timestamp) {
    if (!$timestamp) return '';

    $time = strtotime($timestamp);
    $now  = time();
    $diff = max(0, $now - $time);

    if ($diff < 30) return 'Just now';
    if ($diff < 60) return '1m ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return date('H:i', $time);
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return date('D', $time);
    return date('M d', $time);
}

/**
 * Helper: Format file size
 */
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}