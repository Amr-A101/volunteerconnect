<?php
/**
 * Chat Component - Floating Chat Interface
 * Can be included in any page via: require_once __DIR__ . '/chat.php';
 */

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';

$dbc = $GLOBALS['dbc'] ?? null;

$loggedUser = current_user();
$role = $_SESSION['role'] ?? 'guest';

$is_vol   = ($role === 'vol');
$is_org   = ($role === 'org');
$is_admin = ($role === 'admin');

// If not logged in, don't show chat
if (!$loggedUser) {
    return;
}

$current_user_id = (int)$loggedUser['user_id'];

// Get user display name and avatar
$display_name = '';
$user_avatar = '/volcon/assets/uploads/default-avatar.png';

if ($is_vol) {
    $stmt = $dbc->prepare("SELECT first_name, last_name, profile_picture FROM volunteers WHERE vol_id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $display_name = $result['first_name'] . ' ' . $result['last_name'];
        if ($result['profile_picture']) {
            $user_avatar = $result['profile_picture'];
        }
    }
} elseif ($is_org) {
    $stmt = $dbc->prepare("SELECT name, profile_picture FROM organizations WHERE org_id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $display_name = $result['name'];
        if ($result['profile_picture']) {
            $user_avatar = $result['profile_picture'];
        }
    }
} elseif ($is_admin) {
    $display_name = 'Admin';
}

// Count total unread messages
$stmt = $dbc->prepare("
    SELECT COUNT(DISTINCT cm.message_id) as unread_count
    FROM chat_messages cm
    JOIN chat_participants cp ON cp.conversation_id = cm.conversation_id
    WHERE cp.user_id = ?
    AND cm.sender_id != ?
    AND cm.created_at > COALESCE(cp.last_read_at, '1970-01-01')
    AND cm.is_deleted_for_everyone = 0
    AND NOT EXISTS (
        SELECT 1 FROM chat_message_visibility cmv 
        WHERE cmv.message_id = cm.message_id 
        AND cmv.user_id = ? 
        AND cmv.is_hidden = 1
    )
");
$stmt->bind_param("iii", $current_user_id, $current_user_id, $current_user_id);
$stmt->execute();
$unread_result = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_unread = (int)($unread_result['unread_count'] ?? 0);
?>

<link rel="stylesheet" href="/volcon/assets/css/components/chat.css">

<!-- VC Floating Chat -->
<div id="vc-chat-root" data-user-id="<?= $current_user_id ?>" data-role="<?= $role ?>">

    <!-- Chat Launcher -->
    <div id="vc-chat-launcher" onclick="VCChat.toggleList()">
        <i class="fas fa-comment-dots"></i>
        <?php if ($total_unread > 0): ?>
        <span class="vc-chat-badge" id="vc-chat-unread"><?= $total_unread > 99 ? '99+' : $total_unread ?></span>
        <?php endif; ?>
    </div>

    <!-- Chat List -->
    <div id="vc-chat-list" class="vc-chat-panel vc-hidden">

        <div class="vc-chat-header vc-chat-list-header">
            <span>Messages</span>
            <div class="vc-chat-header-actions">
                <button class="vc-icon-btn" onclick="VCChat.refreshList()" title="Refresh">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <button class="vc-icon-btn" onclick="VCChat.closeAll()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Search -->
        <div class="vc-chat-search">
            <i class="fas fa-search"></i>
            <input 
                type="text" 
                id="vc-chat-search"
                placeholder="Search conversations..."
                onkeyup="VCChat.filterChats(this.value)"
            >
        </div>

        <!-- Chat List Body -->
        <div class="vc-chat-list-body" id="vc-chat-list-body">
            <div class="vc-chat-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <span>Loading conversations...</span>
            </div>
        </div>

    </div>

    <!-- Chat Window -->
    <div id="vc-chat-window" class="vc-chat-panel vc-hidden">
        <div class="vc-chat-header">
            <div class="vc-chat-user">
                <img id="vc-chat-user-pic" src="<?= htmlspecialchars($user_avatar) ?>" alt="">
                <div class="vc-chat-user-info">
                    <span id="vc-chat-user-name">User</span>
                    <a href="#" id="vc-chat-user-profile" class="vc-chat-profile-link" onclick="VCChat.viewProfile(event)">View Profile</a>
                </div>
            </div>
            <div class="vc-chat-actions">
                <button class="vc-icon-btn" onclick="VCChat.back()" title="Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <button class="vc-icon-btn" onclick="VCChat.toggleChatMenu()" title="Options">
                    <i class="fas fa-ellipsis-v"></i>
                </button>
                <button class="vc-icon-btn" onclick="VCChat.closeWindow()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Chat Options Menu -->
        <div id="vc-chat-menu" class="vc-chat-menu vc-hidden">
            <button onclick="VCChat.clearChat()">
                <i class="fas fa-trash"></i> Clear Chat
            </button>
            <button onclick="VCChat.archiveChat()">
                <i class="fas fa-archive"></i> Archive
            </button>
            <button onclick="VCChat.reportChat()">
                <i class="fas fa-flag"></i> Report
            </button>
        </div>

        <div class="vc-chat-body" id="vc-chat-messages">
            <!-- Messages loaded via JS -->
        </div>

        <!-- Typing Indicator -->
        <div id="vc-typing-indicator" class="vc-typing-indicator vc-hidden">
            <div class="vc-typing-dots">
                <span></span><span></span><span></span>
            </div>
            <span id="vc-typing-text">Someone is typing...</span>
        </div>

        <div class="vc-chat-input-wrapper">
            <!-- Attachment Preview -->
            <div id="vc-attachment-preview" class="vc-attachment-preview vc-hidden">
                <div class="vc-attachment-item">
                    <i class="fas fa-file"></i>
                    <span id="vc-attachment-name">file.pdf</span>
                    <button onclick="VCChat.removeAttachment()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="vc-chat-input">
                <input type="file" id="vc-file-input" style="display:none" onchange="VCChat.handleFileSelect(event)">
                <button class="vc-attach-btn" onclick="document.getElementById('vc-file-input').click()" title="Attach file">
                    <i class="fas fa-paperclip"></i>
                </button>
                <textarea 
                    id="vc-message-input" 
                    placeholder="Type a message..." 
                    onkeydown="VCChat.handleKeyPress(event)"
                    oninput="VCChat.handleTyping()"
                ></textarea>
                <button class="vc-send-btn" onclick="VCChat.sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

</div>

<script src="/volcon/assets/js/chat.js"></script>
<script src="/volcon/assets/js/chat-triggers.js"></script>

<script>
// Initialize chat when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    VCChat.init({
        userId: <?= $current_user_id ?>,
        role: '<?= $role ?>',
        displayName: '<?= htmlspecialchars($display_name, ENT_QUOTES) ?>',
        avatar: '<?= htmlspecialchars($user_avatar, ENT_QUOTES) ?>'
    });
});
</script>