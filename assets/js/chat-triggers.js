/**
 * Chat Triggers - Global functions to start chats from anywhere
 * Place this file at: /volcon/assets/js/chat-triggers.js
 */

/**
 * Start a chat with another user
 * @param {number} otherUserId - ID of the user to chat with
 * @param {string} name - Display name of the other user
 * @param {string} avatar - Avatar URL of the other user
 */
window.startChatWith = async function(otherUserId, role, name, avatar) {
    try {
        // Check if VCChat is loaded
        if (typeof VCChat === 'undefined') {
            console.error('VCChat not loaded');
            alert('Chat system is not ready. Please refresh the page.');
            return;
        }

        console.log('Starting chat with:', { otherUserId, role, name, avatar });

        // Show loading state
        const btn = event.target.closest('button');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...';
        }

        const formData = new FormData();
        formData.append('action', 'start_conversation');
        formData.append('other_user_id', otherUserId);
        formData.append('other_user_role', role);

        const response = await fetch('/volcon/app/api/chat_api.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log('Start chat response:', data);

        if (data.success) {

            // Store user info in chat config for later use
            VCChat.config.currentOtherUserId = otherUserId;
            VCChat.config.currentRole = role;

            // Open the chat with user ID for profile link
            VCChat.openChat(
                data.conversation_id, name, avatar, otherUserId, role
            );
            
            // Show the chat window if it's hidden
            const chatRoot = document.getElementById('vc-chat-root');
            if (chatRoot) {
                const chatList = document.getElementById('vc-chat-list');
                const chatWindow = document.getElementById('vc-chat-window');
                
                if (chatList) chatList.classList.add('vc-hidden');
                if (chatWindow) chatWindow.classList.remove('vc-hidden');
            }
        } else {
            alert(data.error || 'Failed to start conversation');
        }

    } catch (error) {
        console.error('Error starting chat:', error);
        alert('Unable to start chat. Please try again.');
    } finally {
        // Restore button state
        const btn = event.target.closest('button');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-comment"></i> Send Message';
        }
    }
};

/**
 * Start a chat with opportunity context
 * @param {number} otherUserId - Organization or volunteer ID
 * @param {number} opportunityId - ID of the opportunity
 * @param {string} name - Display name
 * @param {string} avatar - Avatar URL
 */
window.startChatWithOpportunity = async function(otherUserId, role, opportunityId, name, avatar) {
    try {
        if (typeof VCChat === 'undefined') {
            console.error('VCChat not loaded');
            alert('Chat system is not ready. Please refresh the page.');
            return;
        }

        console.log('Starting chat with opportunity context:', { otherUserId, role, opportunityId, name, avatar });

        const btn = event.target.closest('button');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...';
        }

        const formData = new FormData();
        formData.append('action', 'start_conversation');
        formData.append('other_user_id', otherUserId);
        formData.append('other_user_role', role);
        formData.append('opportunity_id', opportunityId);

        const response = await fetch('/volcon/app/api/chat_api.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log('Start chat response:', data);

        if (data.success) {
            VCChat.openChat(
                data.conversation_id, name, avatar, otherUserId, role
            );
            
            const chatRoot = document.getElementById('vc-chat-root');
            if (chatRoot) {
                const chatList = document.getElementById('vc-chat-list');
                const chatWindow = document.getElementById('vc-chat-window');
                
                if (chatList) chatList.classList.add('vc-hidden');
                if (chatWindow) chatWindow.classList.remove('vc-hidden');
            }
        } else {
            alert(data.error || 'Failed to start conversation');
        }

    } catch (error) {
        console.error('Error starting chat:', error);
        alert('Unable to start chat. Please try again.');
    } finally {
        const btn = event.target.closest('button');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-comment"></i> Ask About This Opportunity';
        }
    }
};

// Log when script is loaded
console.log('Chat triggers loaded successfully');