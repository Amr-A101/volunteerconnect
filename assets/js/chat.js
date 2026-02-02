/**
 * VCChat - Floating Chat Interface
 * Frontend JavaScript for real-time messaging
 */

const VCChat = {
    config: {
        userId: null,
        role: null,
        displayName: null,
        avatar: null,
        apiUrl: '/volcon/app/api/chat_api.php',
        pollInterval: 5000,
        typingTimeout: null,
        currentConversationId: null,
        selectedFile: null,
        isSending: false
    },

    conversations: [],
    messages: [],
    pollTimer: null,
    lastMessageId: null,

    /**
     * Initialize chat system
     */
    init(userConfig) {
        this.config = { ...this.config, ...userConfig };
        this.loadConversations();
        this.startPolling();
        this.setupEventListeners();
    },

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Click outside to close menus
        document.addEventListener('click', (e) => {
            const menu = document.getElementById('vc-chat-menu');
            if (menu && !menu.classList.contains('vc-hidden')) {
                if (!e.target.closest('#vc-chat-menu') && !e.target.closest('.vc-icon-btn')) {
                    menu.classList.add('vc-hidden');
                }
            }
        });
    },

    /**
     * Load conversations from server
     */
    async loadConversations() {
        try {
            const response = await fetch(`${this.config.apiUrl}?action=get_conversations`);
            const data = await response.json();

            if (data.success) {
                this.conversations = data.conversations;
                this.renderChatList();
                this.updateUnreadBadge();
            } else {
                this.showError('Failed to load conversations');
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
            this.showEmptyState();
        }
    },

    /**
     * Render chat list
     */
    renderChatList(filteredData = null) {
        const listBody = document.getElementById('vc-chat-list-body');
        const data = filteredData || this.conversations;

        if (!data || data.length === 0) {
            this.showEmptyState();
            return;
        }

        listBody.innerHTML = '';

        data.forEach(chat => {
            const item = this.createChatItem(chat);
            listBody.appendChild(item);
        });
    },

    /**
     * Create chat list item element
     */
    createChatItem(chat) {
        const div = document.createElement('div');
        div.className = 'vc-chat-item';
        div.onclick = () => this.openChat(chat.conversation_id, chat.name, chat.avatar, chat.other_user_id, chat.other_user_role);

        const isItalic = chat.is_deleted || chat.has_attachment;
        const messageClass = isItalic ? 'vc-chat-message italic' : 'vc-chat-message';

        const unreadBadge = chat.unread > 0
            ? `<div class="vc-chat-unread">${chat.unread > 99 ? '99+' : chat.unread}</div>`
            : '';

        const displayName = chat.name.length > 25 ? chat.name.substring(0, 22) + '...' : chat.name;

        div.innerHTML = `
            <div class="vc-chat-avatar">
                <img src="${this.escapeHtml(chat.avatar)}" alt="${this.escapeHtml(chat.name)}" 
                     onerror="this.src='/volcon/assets/uploads/default-avatar.png'">
            </div>
            <div class="vc-chat-content">
                <div class="vc-chat-top">
                    <div class="vc-chat-name" title="${this.escapeHtml(chat.name)}">${this.escapeHtml(displayName)}</div>
                    <div class="vc-chat-time">${this.escapeHtml(chat.last_time)}</div>
                </div>
                <div class="vc-chat-last">
                    <div class="${messageClass}">${this.escapeHtml(chat.last_message)}</div>
                    ${unreadBadge}
                </div>
            </div>
        `;

        return div;
    },

    /**
     * Show empty state
     */
    showEmptyState() {
        const listBody = document.getElementById('vc-chat-list-body');
        listBody.innerHTML = `
            <div class="vc-chat-empty">
                <i class="fas fa-comments" style="font-size: 48px; color: #ddd; margin-bottom: 16px;"></i>
                <p>No conversations yet</p>
                <small>Start a conversation with an organization or volunteer</small>
            </div>
        `;
    },

    /**
     * Show error message
     */
    showError(message) {
        const listBody = document.getElementById('vc-chat-list-body');
        listBody.innerHTML = `
            <div class="vc-chat-empty">
                <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ef4444; margin-bottom: 16px;"></i>
                <p>${this.escapeHtml(message)}</p>
                <button class="vc-btn-retry" onclick="VCChat.refreshList()">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        `;
    },

    /**
     * Filter chats based on search
     */
    filterChats(keyword) {
        keyword = keyword.toLowerCase().trim();

        if (!keyword) {
            this.renderChatList();
            return;
        }

        const filtered = this.conversations.filter(c =>
            c.name.toLowerCase().includes(keyword) ||
            c.last_message.toLowerCase().includes(keyword)
        );

        this.renderChatList(filtered);
    },

    /**
     * Open chat window
     */
    async openChat(conversationId, name, avatar, otherUserId = null, otherUserRole = null) {
        this.config.currentConversationId = conversationId;
        this.config.currentOtherUserId = otherUserId;
        this.config.currentOtherUserRole = otherUserRole;
        
        console.log('Opening chat with:', { conversationId, otherUserId, otherUserRole });

        // Update header
        document.getElementById('vc-chat-user-name').textContent = name;
        document.getElementById('vc-chat-user-pic').src = avatar;
        
        // Update profile link if we have user ID
        const profileLink = document.getElementById('vc-chat-user-profile');
        if (profileLink && otherUserId) {
            profileLink.style.display = 'inline';
            profileLink.dataset.userId = otherUserId;
            profileLink.dataset.userRole = otherUserRole || 'vol';
        } else if (profileLink) {
            profileLink.style.display = 'none';
        }

        // Show window, hide list
        document.getElementById('vc-chat-list').classList.add('vc-hidden');
        document.getElementById('vc-chat-window').classList.remove('vc-hidden');

        // Load messages
        await this.loadMessages(conversationId);

        // Mark as read
        this.markAsRead(conversationId);

        // Focus input
        setTimeout(() => {
            document.getElementById('vc-message-input').focus();
        }, 100);
    },

    /**
     * Load messages for conversation
     */
    async loadMessages(conversationId, silent = false) {
        const messagesContainer = document.getElementById('vc-chat-messages');
        
        if (!silent && messagesContainer && this.config.currentConversationId === conversationId) {
            messagesContainer.innerHTML = '<div class="vc-chat-loading">Loading messages...</div>';
        }

        try {
            const response = await fetch(`${this.config.apiUrl}?action=get_messages&conversation_id=${conversationId}`);
            const data = await response.json();

            if (data.success) {
                const newMessages = data.messages;

                const containerEmpty =
                    !messagesContainer ||
                    messagesContainer.innerHTML.trim() === '' ||
                    messagesContainer.querySelector('.vc-chat-loading');

                if (
                    containerEmpty ||
                    JSON.stringify(newMessages) !== JSON.stringify(this.messages)
                ) {
                    const shouldScroll = this.isScrolledToBottom(messagesContainer);
                    this.messages = newMessages;
                    this.renderMessages();

                    if (shouldScroll || !silent) {
                        this.scrollToBottom();
                    }
                }
            } else {
                if (!silent && messagesContainer) {
                    messagesContainer.innerHTML = '<div class="vc-chat-error">Failed to load messages</div>';
                }
            }
        } catch (error) {
            console.error('Error loading messages:', error);
            if (!silent && messagesContainer) {
                messagesContainer.innerHTML = '<div class="vc-chat-error">Error loading messages. Please try again.</div>';
            }
        }
    },

    /**
     * Check if scrolled to bottom
     */
    isScrolledToBottom(element) {
        if (!element) return true;
        return element.scrollHeight - element.scrollTop <= element.clientHeight + 50;
    },

    /**
     * Render messages
     */
    renderMessages() {
        const container = document.getElementById('vc-chat-messages');
        container.innerHTML = '';

        if (this.messages.length === 0) {
            container.innerHTML = '<div class="vc-chat-empty-messages">No messages yet. Start the conversation!</div>';
            return;
        }

        this.messages.forEach(msg => {
            const messageEl = this.createMessageElement(msg);
            container.appendChild(messageEl);
        });
    },

    /**
     * Create message element
     */
    createMessageElement(msg) {
        const div = document.createElement('div');
        const alignClass = msg.is_own ? 'vc-msg-right' : 'vc-msg-left';
        div.className = `vc-chat-message-wrapper ${alignClass}`;

        if (msg.message_type === 'system') {
            div.className = 'vc-system-message';
            div.innerHTML = `<span>${this.escapeHtml(msg.message_text)}</span>`;
            return div;
        }

        // Handle message text
        let messageContent = '';
        if (msg.is_deleted) {
            messageContent = '<em style="color: #999;">Message deleted</em>';
        } else if (msg.message_text && msg.message_text.trim()) {
            messageContent = `<div class="vc-msg-text">${this.formatMessageText(msg.message_text)}</div>`;
        }

        // Attachments
        const attachmentsHtml = msg.attachments && msg.attachments.length > 0
            ? this.renderAttachments(msg.attachments)
            : '';

        // Don't show bubble if no content
        if (!messageContent && !attachmentsHtml) {
            return div; // Return empty div
        }

        // Actions
        let actionsHtml = '';
        if (msg.is_own && !msg.is_deleted) {
            actionsHtml = `<div class="vc-msg-actions">
                    <button onclick="VCChat.deleteMessage(${msg.message_id}, false)" title="Delete for me">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button onclick="VCChat.deleteMessage(${msg.message_id}, true)" title="Delete for everyone">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>`;
        } else if (!msg.is_own && !msg.is_deleted) {
            const flagClass = msg.is_flagged_by_me ? 'vc-flagged' : '';
            const flagTitle = msg.is_flagged_by_me ? 'Already reported' : 'Report';
            actionsHtml = `<div class="vc-msg-actions">
                    <button onclick="VCChat.flagMessage(${msg.message_id})" 
                            class="${flagClass}" 
                            title="${flagTitle}"
                            ${msg.is_flagged_by_me ? 'disabled' : ''}>
                        <i class="fas fa-flag"></i>
                    </button>
                </div>`;
        }

        div.innerHTML = `
            ${!msg.is_own ? `<img class="vc-msg-avatar" src="${this.escapeHtml(msg.sender_avatar)}" alt="" onerror="this.src='/volcon/assets/uploads/default-avatar.png'">` : ''}
            <div class="vc-chat-bubble ${msg.is_own ? 'vc-bubble-own' : 'vc-bubble-other'}">
                ${!msg.is_own ? `<div class="vc-msg-sender">${this.escapeHtml(msg.sender_name)}</div>` : ''}
                ${messageContent}
                ${attachmentsHtml}
                <div class="vc-msg-time">${this.escapeHtml(msg.time_formatted)}</div>
                ${actionsHtml}
            </div>
        `;

        return div;
    },

    /**
     * Render attachments
     */
    renderAttachments(attachments) {
        let html = '<div class="vc-msg-attachments">';
        attachments.forEach(att => {
            const isImage = att.type && att.type.startsWith('image/');
            if (isImage) {
                html += `<img src="${this.escapeHtml(att.path)}" class="vc-attachment-image" onclick="window.open('${this.escapeHtml(att.path)}', '_blank')" onerror="this.style.display='none'">`;
            } else {
                const icon = this.getFileIcon(att.type);
                html += `
                    <a href="${this.escapeHtml(att.path)}" target="_blank" class="vc-attachment-file">
                        <i class="${icon}"></i>
                        <span>${att.size || 'File'}</span>
                    </a>
                `;
            }
        });
        html += '</div>';
        return html;
    },

    /**
     * Get file icon based on type
     */
    getFileIcon(type) {
        if (!type) return 'fas fa-file';
        if (type.includes('pdf')) return 'fas fa-file-pdf';
        if (type.includes('word')) return 'fas fa-file-word';
        if (type.includes('excel') || type.includes('spreadsheet')) return 'fas fa-file-excel';
        if (type.includes('image')) return 'fas fa-file-image';
        return 'fas fa-file';
    },

    /**
     * Format message text (linkify, etc.)
     */
    formatMessageText(text) {
        if (!text) return '';

        text = this.escapeHtml(text);
        text = text.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>');
        text = text.replace(/\n/g, '<br>');

        return text;
    },

    /**
     * Send message
     */
    async sendMessage() {
        // Prevent double sending
        if (this.config.isSending) {
            console.log('Already sending...');
            return;
        }

        const input = document.getElementById('vc-message-input');
        const message = input.value.trim();

        if (!message && !this.config.selectedFile) {
            return;
        }

        if (!this.config.currentConversationId) {
            alert('No conversation selected');
            return;
        }

        // Set sending flag
        this.config.isSending = true;

        // Disable send button
        const sendBtn = document.querySelector('.vc-send-btn');
        const originalHtml = sendBtn.innerHTML;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('conversation_id', this.config.currentConversationId);
        formData.append('message_text', message);

        if (this.config.selectedFile) {
            formData.append('attachment', this.config.selectedFile);
        }

        try {
            const response = await fetch(this.config.apiUrl, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Clear input
                input.value = '';
                this.removeAttachment();

                // Immediately reload messages (not silent)
                await this.loadMessages(this.config.currentConversationId, false);

                // Update conversation list in background
                this.loadConversations();
            } else {
                alert('Failed to send message: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Error sending message');
        } finally {
            // Re-enable button and reset flag
            sendBtn.disabled = false;
            sendBtn.innerHTML = originalHtml;
            this.config.isSending = false;
        }
    },

    /**
     * Handle key press in input
     */
    handleKeyPress(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.sendMessage();
        }
    },

    /**
     * Handle typing indicator
     */
    handleTyping() {
        // Placeholder for typing indicators
    },

    /**
     * Handle file selection
     */
    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type. Only images (JPG, PNG, GIF) and documents (PDF, DOC, DOCX) are allowed.');
            event.target.value = '';
            return;
        }

        const maxSize = 5 * 1024 * 1024; // 5MB
        if (file.size > maxSize) {
            alert('File is too large. Maximum size is 5MB.');
            event.target.value = '';
            return;
        }

        this.config.selectedFile = file;

        // Show preview
        const preview = document.getElementById('vc-attachment-preview');
        const nameSpan = document.getElementById('vc-attachment-name');
        nameSpan.textContent = file.name;
        preview.classList.remove('vc-hidden');
    },

    /**
     * Remove attachment
     */
    removeAttachment() {
        this.config.selectedFile = null;
        document.getElementById('vc-file-input').value = '';
        document.getElementById('vc-attachment-preview').classList.add('vc-hidden');
    },

    /**
     * Mark conversation as read
     */
    async markAsRead(conversationId) {
        try {
            const formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('conversation_id', conversationId);

            await fetch(this.config.apiUrl, {
                method: 'POST',
                body: formData
            });

            // Update unread badge
            this.updateUnreadBadge();
        } catch (error) {
            console.error('Error marking as read:', error);
        }
    },

    /**
     * Delete message
     */
    async deleteMessage(messageId, forEveryone) {
        const confirmMsg = forEveryone
            ? 'Delete this message for everyone?'
            : 'Delete this message for you?';

        if (!confirm(confirmMsg)) return;

        try {
            const formData = new FormData();
            formData.append('action', 'delete_message');
            formData.append('message_id', messageId);
            formData.append('delete_for_everyone', forEveryone ? 1 : 0);

            const response = await fetch(this.config.apiUrl, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await this.loadMessages(this.config.currentConversationId, false);
            }
        } catch (error) {
            console.error('Error deleting message:', error);
        }
    },

    /**
     * Flag message
     */
    async flagMessage(messageId) {
        const category = prompt('Report reason:\n1. Spam\n2. Harassment\n3. Inappropriate\n4. Other\n\nEnter number:');
        
        if (!category) return; // User cancelled
        
        const categories = { '1': 'spam', '2': 'harassment', '3': 'inappropriate', '4': 'other' };
        const selectedCategory = categories[category] || 'other';

        try {
            const formData = new FormData();
            formData.append('action', 'flag_message');
            formData.append('message_id', messageId);
            formData.append('category', selectedCategory);

            const response = await fetch(this.config.apiUrl, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                alert('Message reported successfully. Thank you for helping keep our community safe.');
                // Reload messages to update flag status
                await this.loadMessages(this.config.currentConversationId, false);
            } else {
                alert(data.error || 'Failed to report message');
            }
        } catch (error) {
            console.error('Error flagging message:', error);
            alert('Error reporting message');
        }
    },

    /**
     * Clear chat
     */
    async clearChat() {
        if (!confirm('Clear this chat? Messages will be hidden for you only.')) return;
        alert('Feature coming soon');
    },

    /**
     * Archive conversation
     */
    async archiveChat() {
        if (!confirm('Archive this conversation?')) return;

        try {
            const formData = new FormData();
            formData.append('action', 'archive_conversation');
            formData.append('conversation_id', this.config.currentConversationId);

            const response = await fetch(this.config.apiUrl, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                this.back();
                this.loadConversations();
            }
        } catch (error) {
            console.error('Error archiving chat:', error);
        }
    },

    /**
     * Report chat
     */
    reportChat() {
        alert('Report sent. We will review this conversation.');
    },

    /**
     * Update unread badge
     */
    updateUnreadBadge() {
        const badge = document.getElementById('vc-chat-unread');
        const total = this.conversations.reduce((sum, c) => sum + c.unread, 0);

        if (total > 0) {
            if (!badge) {
                const launcher = document.getElementById('vc-chat-launcher');
                const newBadge = document.createElement('span');
                newBadge.className = 'vc-chat-badge';
                newBadge.id = 'vc-chat-unread';
                newBadge.textContent = total > 99 ? '99+' : total;
                launcher.appendChild(newBadge);
            } else {
                badge.textContent = total > 99 ? '99+' : total;
                badge.style.display = 'flex';
            }
        } else if (badge) {
            badge.style.display = 'none';
        }
    },

    /**
     * Start polling for new messages
     */
    startPolling() {
        this.pollTimer = setInterval(() => {
            // Only poll messages if chat window is open
            if (this.config.currentConversationId) {
                this.loadMessages(this.config.currentConversationId, true); // Silent refresh
            }
            // Always refresh conversation list
            this.loadConversations();
        }, this.config.pollInterval);
    },

    /**
     * Stop polling
     */
    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    },

    /**
     * Refresh conversation list
     */
    refreshList() {
        this.loadConversations();
    },

    /**
     * Toggle chat list
     */
    toggleList() {
        const list = document.getElementById('vc-chat-list');
        const window = document.getElementById('vc-chat-window');

        list.classList.toggle('vc-hidden');
        window.classList.add('vc-hidden');

        if (!list.classList.contains('vc-hidden')) {
            this.loadConversations();
        }
    },

    /**
     * Toggle chat menu
     */
    toggleChatMenu() {
        document.getElementById('vc-chat-menu').classList.toggle('vc-hidden');
    },

    /**
     * Go back to list
     */
    back() {
        document.getElementById('vc-chat-list').classList.remove('vc-hidden');
        document.getElementById('vc-chat-window').classList.add('vc-hidden');
        this.config.currentConversationId = null;
        this.messages = [];
    },

    /**
     * Close chat window
     */
    closeWindow() {
        document.getElementById('vc-chat-window').classList.add('vc-hidden');
        this.config.currentConversationId = null;
        this.messages = [];  
    },

    /**
     * Close all panels
     */
    closeAll() {
        document.getElementById('vc-chat-list').classList.add('vc-hidden');
        document.getElementById('vc-chat-window').classList.add('vc-hidden');
        this.config.currentConversationId = null;
    },

    /**
     * Scroll to bottom of messages
     */
    scrollToBottom() {
        const container = document.getElementById('vc-chat-messages');
        if (container) {
            setTimeout(() => {
                container.scrollTop = container.scrollHeight;
            }, 100);
        }
    },

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * View user profile
     */
    viewProfile(event) {
        event.preventDefault();
        const link = event.currentTarget;
        const userId = link.dataset.userId;
        const userRole = link.dataset.userRole || 'vol';
        
        if (!userId) {
            alert('Profile not available');
            return;
        }

        // Determine profile page based on role
        const profileUrl = userRole === 'org' 
            ? `/volcon/app/profile_org.php?id=${userId}`
            : `/volcon/app/profile_vol.php?id=${userId}`;
        
        window.open(profileUrl, '_blank');
    }
};